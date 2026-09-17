<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite must disable foreign keys before Laravel rebuilds the users table.
    public $withinTransaction = false;

    public function up(): void
    {
        $this->replaceRoleVocabulary('admin', 'superadmin', false);
    }

    public function down(): void
    {
        $this->replaceRoleVocabulary('superadmin', 'admin', true);
    }

    private function replaceRoleVocabulary(string $from, string $to, bool $rollback): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite', 'pgsql', 'sqlsrv'], true)) {
            throw new RuntimeException("Unsupported users.role migration driver: {$driver}");
        }

        if ($driver === 'sqlite' && $connection->transactionLevel() > 0) {
            throw new RuntimeException('SQLite role migration must run outside an existing transaction so foreign keys can be disabled safely.');
        }

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $foreignKeys = $driver === 'sqlite' && (bool) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
            if ($foreignKeys) {
                Schema::disableForeignKeyConstraints();
            }
            try {
                $this->changeEnumRole($from, $to, $rollback);
            } finally {
                if ($foreignKeys) {
                    Schema::enableForeignKeyConstraints();
                }
            }
            return;
        }

        $this->changeCheckedRole($driver, $from, $to, $rollback);
    }

    private function changeEnumRole(string $from, string $to, bool $rollback): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'superadmin', 'customer', 'affiliate'])->change();
        });
        $this->transitionRoleData($from, $to, $rollback);
        Schema::table('users', function (Blueprint $table) use ($to) {
            $table->enum('role', [$to, 'customer', 'affiliate'])->change();
        });
    }

    private function changeCheckedRole(string $driver, string $from, string $to, bool $rollback): void
    {
        $connection = DB::connection();
        // Laravel implements enum as a column CHECK on PostgreSQL and SQL Server.
        $table = $connection->getTablePrefix().'users';
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable('users');
        $constraints = $driver === 'pgsql'
            ? DB::select("SELECT c.conname AS name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey) WHERE c.conrelid = to_regclass(?) AND c.contype = 'c' AND a.attname = 'role' AND cardinality(c.conkey) = 1", [$table])
            : DB::select("SELECT cc.name FROM sys.check_constraints cc JOIN sys.columns col ON col.object_id = cc.parent_object_id AND col.column_id = cc.parent_column_id WHERE cc.parent_object_id = OBJECT_ID(?) AND col.name = 'role'", [$table]);

        if (count($constraints) !== 1) {
            throw new RuntimeException('Expected exactly one users.role CHECK constraint; inspect schema before migrating.');
        }

        DB::transaction(function () use ($constraints, $grammar, $wrappedTable, $from, $to, $rollback) {
            $constraint = $grammar->wrap($constraints[0]->name);
            DB::statement("ALTER TABLE {$wrappedTable} DROP CONSTRAINT {$constraint}");
            $this->transitionRoleData($from, $to, $rollback);
            $role = $grammar->wrap('role');
            DB::statement("ALTER TABLE {$wrappedTable} ADD CONSTRAINT {$constraint} CHECK ({$role} IN ('{$to}', 'customer', 'affiliate'))");
        });
    }

    private function transitionRoleData(string $from, string $to, bool $rollback): void
    {
        if ($rollback) {
            DB::table('users')->where('role', $from)->update(['role' => $to]);
            return;
        }

        $legacyAdminIds = DB::table('users')->where('role', 'admin')->pluck('id');
        if ($legacyAdminIds->isEmpty()) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\User::class)
            ->whereIn('tokenable_id', $legacyAdminIds)
            ->delete();
        DB::table('sessions')->whereIn('user_id', $legacyAdminIds)->delete();
        DB::table('users')->whereIn('id', $legacyAdminIds)->update([
            'role' => 'customer',
            'credential_version' => DB::raw('credential_version + 1'),
        ]);
        foreach ($legacyAdminIds as $legacyAdminId) {
            DB::table('users')->where('id', $legacyAdminId)->update([
                'remember_token' => \Illuminate\Support\Str::random(60),
            ]);
        }
    }
};
