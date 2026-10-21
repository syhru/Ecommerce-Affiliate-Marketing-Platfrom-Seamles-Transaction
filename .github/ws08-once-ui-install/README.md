# WS-08 Once UI Ubuntu CI Install Lifecycle Runner

Direktori ini memuat seluruh komponen yang diperlukan untuk memvalidasi daur hidup instalasi (*install lifecycle*) patch Once UI pada runner GitHub Actions Ubuntu (`ubuntu-latest`).

Komponen ini dirancang untuk dieksekusi secara terpisah dalam lingkungan CI terisolasi tanpa memutasi frontend utama atau lingkungan runtime produksi.

---

## 1. Struktur Direktori

```text
.github/ws08-once-ui-install/
├── inputs/
│   ├── .gitattributes                          # Proteksi -text agar git checkout tidak mengubah CRLF/LF
│   ├── once-ui-patch.cjs                       # Helper patch yang telah diverifikasi (SHA256: ed9c8a12...)
│   ├── @once-ui-system+core+2.0.0.patch        # Berkas patch Once UI (SHA256: 8738f5c1...)
│   └── once-ui-source-identity.json            # Berkas identitas dan target hashes (SHA256: 744a34fb...)
├── run-lifecycle.cjs                           # Runner script Node.js ter-remediasi untuk CI
├── evidence/                                   # Direktori keluaran artefak bukti terarah (dibuat saat runtime)
└── README.md                                   # Dokumentasi teknis runner (berkas ini)
```

---

## 2. Alur Eksekusi Runner Script (`run-lifecycle.cjs`)

Runner script dirancang tanpa dependensi eksternal tambahan (hanya modul standar `node:fs`, `node:path`, `node:crypto`, `node:child_process`, `node:os`).

1. **Inisialisasi Provenance & Snapshot Raw Awal**:
   - Direktori `evidence/` dibuat di awal eksekusi.
   - `TASK_OWNERSHIP.json` dan `ci-summary.json` (status awal `RUNNING`) ditulis sedini mungkin dengan mencatat metadata aman: timestamp, OS, release, arch, Node version, runner script SHA256, dan allowlisted GitHub context (`GITHUB_SHA`, `GITHUB_REF`, `GITHUB_EVENT_NAME`, `GITHUB_WORKFLOW`, `GITHUB_RUN_ID`, `ImageOS`).
   - `checkout-initial-snapshot.json` ditulis langsung ke disk sebelum pemeriksaan lanjutan, mencatat path, required flag, presence status, dan raw SHA-256 untuk sepuluh berkas yang dilindungi: manifest, lockfile, next.config.ts, tsconfig.json, tiga accepted inputs CI dan tiga canonical frontend inputs. Presence canonical node_modules dicatat terpisah; fresh CI checkout wajib absent.
   - Berkas wajib yang hilang (`next.config.ts`, `tsconfig.json`, manifest, lockfile, 3 accepted inputs CI, 3 canonical inputs) ditolak secara tegas (*fail-closed*) saat intake.

2. **Pengukuran Masukan Inti & Validasi Checksum**:
   - Seluruh 8 input inti (`once-ui-patch.cjs`, patch, identity, `package.json`, `package-lock.json`, tiga canonical frontend inputs) diukur secara fisik dan hasilnya langsung ditulis ke `intake-input-hashes.json` dan `TASK_OWNERSHIP.json` **sebelum** assertion dijalankan.
   - Jika terdapat ketidakcocokan hash masukan atau berkas hilang, proses langsung dihentikan (*fail-closed*) dengan exit code 1; seluruh bukti provenance dan actual wrong hashes tersimpan tanpa menjalankan satu pun subprocess.

3. **Isolasi Fisik di `RUNNER_TEMP` & Deteksi Konflik Skrip Manifest Scratch**:
   - Membuat direktori task unik di bawah `RUNNER_TEMP` yang diselesaikan secara fisik (`fs.realpathSync`).
   - Direktori scratch dicatat ke `TASK_OWNERSHIP.json` setelah dibuat. Versi `npm` diukur dan ditambahkan ke ownership.
   - Menginisialisasi dua salinan bersih: `normal` dan `ignored-scripts`.
   - Menyalin source aplikasi frontend dari checkout dengan pengecualian ketat (`node_modules`, `.next`, `.git`, `tsconfig.tsbuildinfo`, `.env*` tidak disalin).
   - Checkout activated sudah membawa helper/patch/identity. Copier mempertahankan bytes tersebut lalu memvalidasi exact identities sebelum penggunaan, tanpa overwrite dari inputs CI. Snapshot memeriksa physical components, regular single-link files, dan menolak symlink/junction/alias pada protected inputs.
   - Memvalidasi 3 lifecycle scripts activated pada `package.json` salinan scratch dengan **pemeriksaan konflik terarah**:
     * `postinstall`: `"node scripts/once-ui-patch.cjs apply"`
     * `verify:once-ui`: `"node scripts/once-ui-patch.cjs verify"`
     * `prebuild`: `"npm run verify:once-ui"`
     * Kebijakan konflik: jika key skrip sudah ada dengan nilai berbeda, proses **ditolak secara fail-closed** (tidak ada penimpaan diam-diam); jika nilai identik, dicatat dan dipertahankan.
   - Mencatat integritas `package-lock.json` pada kedua salinan scratch secara inkremental ke `lockfile-verifications.json`.

4. **Skenario A (Normal Install & Prebuild Gate Ordering)**:
   - Menjalankan `npm ci --include=dev --foreground-scripts` (exit 0).
   - Memverifikasi eksekusi otomatis root `postinstall` oleh npm dan pelaporan sukses oleh tool helper.
   - Mencatat verifikasi lockfile pasca-install secara inkremental ke `lockfile-verifications.json`.
   - Mengukur hash kelima berkas target fisik dan memverifikasi kesesuaian 100% dengan final hashes identity.
   - Menjalankan verifikasi eksplisit `node scripts/once-ui-patch.cjs verify` (exit 0).
   - Menjalankan `npm run typecheck` (`tsc --noEmit`, exit 0).
   - Menjalankan `npm run build` (`prebuild` -> `next build`, exit 0).
   - **Enforcement Gerbang Prebuild**: Memeriksa bahwa eksekusi prebuild dan verifikasi sukses oleh helper (`VERIFIED: exact accepted Once UI patch`) **benar-benar mendahului** proses kompilasi Next.js (`next build`). Jika marker tidak lengkap atau urutan salah, skenario digagalkan seketika.
   - Mencatat verifikasi lockfile pasca-build secara inkremental.
   - Menjalankan repeat apply dan repeat verify: mengukur target sebelum dan sesudah repeat, membuktikan identitas 100% (tidak ada drift), menyimpan kedua berkas pengukuran target penuh (`scenario-a-repeat-before-measured-targets.json` dan `scenario-a-repeat-after-measured-targets.json`), serta mencatat lockfile pasca-repeat.

5. **Skenario B (Ignored-scripts, Negative Verify Refusal & Target Immutability)**:
   - Menjalankan `npm ci --include=dev --ignore-scripts` (exit 0).
   - Mencatat verifikasi lockfile pasca-install salinan ignored-scripts.
   - **Sebelum apply atau verify**: Mengukur dan mencatat actual SHA-256 kelima target fisik di disk ke `scenario-b-pre-apply-measured-targets.json` (membuktikan kesesuaian dengan original hashes identity).
   - Menjalankan verifikasi eksplisit `node scripts/once-ui-patch.cjs verify`.
   - **Penegakan Penolakan Negatif**: Membedakan penolakan helper yang diharapkan dari kegagalan proses:
     * Wajib tidak ada error spawn (`!res.error`).
     * Wajib tidak dihentikan oleh sinyal (`!res.signal`).
     * Wajib keluar dengan kode keluar 1 (`res.status === 1`); exit code 0 menggagalkan pengujian sebagai pelanggaran gerbang keamanan (*security gate failure*).
     * `stderr` wajib memuat pesan penolakan `ONCE UI PATCH REFUSED: Accepted Once UI patch missing or modified`.
   - **Enforcement Immutabilitas Target Pasca-Penolakan**: Mengukur kembali kelima target fisik setelah penolakan ke `scenario-b-after-refusal-measured-targets.json`. Jika ada target yang berubah, skenario digagalkan seketika sebelum tahap recovery.
   - Mencatat verifikasi lockfile pasca-refusal.

6. **Skenario C (Explicit Recovery & Pemisahan Log)**:
   - Menjalankan `node scripts/once-ui-patch.cjs apply` (exit 0) dan mencatat ke `scenario-c-recovery-apply.log`.
   - Mencatat verifikasi lockfile pasca-recovery apply.
   - Menjalankan `node scripts/once-ui-patch.cjs verify` (exit 0) dan mencatat ke `scenario-c-recovery-verify.log`.
   - Kedua log disimpan terpisah sehingga tidak saling menimpa; log terpadu dengan pemisah tahap tegas juga disimpan di `scenario-c-recovery.log`.
   - Mengukur kembali kelima target pasca-recovery ke `scenario-c-post-recovery-measured-targets.json` (harus 100% cocok dengan final hashes).
   - Mencatat verifikasi lockfile pasca-recovery verify.

7. **Snapshot Raw Akhir & Verifikasi Integritas Scoped Checkout**:
   - `checkout-final-snapshot.json` ditulis langsung ke disk **sebelum** assertion comparison dijalankan.
   - Menjalankan evaluasi integritas checkout dinamis:
     * Berkas wajib (`next.config.ts`, `tsconfig.json`, manifest, lockfile, 3 accepted inputs CI, 3 canonical inputs) wajib ada di initial dan final; missing files (`null === null`) ditolak keras.
     * Berkas canonical tidak termutasi selama run.
     * Manifest dan lockfile canonical cocok dengan baseline expected.
     * Tiga canonical frontend inputs wajib ada, exact accepted bytes pada initial/final, dan tidak berubah selama run. Direktori scripts/patches tidak lagi dilarang secara total.
     * `node_modules` canonical tidak dibuat saat execution.
   - Hasil evaluasi dicatat ke `checkout-integrity.json`. Jika ada kegagalan, melempar error dengan referensi snapshot raw.

8. **Pengumpulan Bukti & Ringkasan Mesin**:
   - Menulis `checksums.json` komprehensif yang memuat seluruh pengukuran target penuh (normal final, repeat before/after, ignored original, after refusal, post recovery), riwayat inkremental `lockfile-verifications.json`, scratch manifests, dan rujukan snapshot checkout.
   - Menulis `machine-summary.json` dan memfinalisasi `ci-summary.json` (`overall_verdict: PASS`).
   - Seluruh artefak di `evidence/` diunggah oleh GitHub Actions melalui `actions/upload-artifact@v4` dengan kondisi `if: ${{ !cancelled() }}`.

---

## 3. Daftar Kebutuhan Publikasi dan Commit CI

Untuk mengevaluasi kesiapan publikasi, komponen commit dipisahkan ke dalam 4 kategori tegas:

### Kategori 1: File Workflow, Runner, dan Input Baru (Wajib dalam Commit Run)
Berkas-berkas ini merupakan infrastruktur CI baru yang harus tersedia pada checkout commit runner:
1. `.github/workflows/ws08-once-ui-install-lifecycle.yml`
2. `.github/ws08-once-ui-install/README.md`
3. `.github/ws08-once-ui-install/run-lifecycle.cjs`
4. `.github/ws08-once-ui-install/inputs/.gitattributes`
5. `.github/ws08-once-ui-install/inputs/once-ui-patch.cjs`
6. `.github/ws08-once-ui-install/inputs/@once-ui-system+core+2.0.0.patch`
7. `.github/ws08-once-ui-install/inputs/once-ui-source-identity.json`

### Kategori 2: Manifest Activated, Lockfile dan Canonical Inputs (Wajib dalam Checkout Run)
Runner script memverifikasi hash exact canonical `frontend/package.json` dan `frontend/package-lock.json`.
Baseline aktivasi sekarang (bukan baseline historis runner sebelum aktivasi):
- `frontend/package.json` (Expected SHA256: `61de8012c454de332b54f6e393b2972ff3f323e9067bfce612115f0922c0ac3c`)
- `frontend/package-lock.json` (Expected SHA256: `4bd4f21c8ab404c4557664bb28b144371c71c14759a9cde627f1dbd25cb11894`)

Input minimum publikasi aktivasi juga mencakup `frontend/scripts/once-ui-patch.cjs`, kedua files pada `frontend/patches/once-ui/`, `frontend/patches/once-ui/.gitattributes`, `frontend/.gitattributes` (dua manifest rules dipertahankan, helper -text ditambahkan), serta explicit gate di `.github/workflows/verification.yml`. Tiga canonical inputs harus byte-identical dengan inputs CI; jangan mengubah expected source hashes. Lockfile tetap exact, tanpa dependency change. Files CI/inputs lama tetap dibutuhkan; bukti artifacts lokal bukan runtime input.

### Kategori 3: Source dan Konfigurasi Frontend (Tersedia dari Checkout Commit)
Copier runner script (`copyFrontend`) menyalin seluruh pohon direktori frontend dari checkout commit ke direktori scratch terisolasi di `RUNNER_TEMP` sesuai aturan pengecualian (*exclusions*). Pemisahan fungsional berkas yang disalin:

1. **Aplikasi, Compiled Imports, Assets, Middleware & Konfigurasi Build/Compiler (Build-Required)**:
   Berkas-berkas ini digunakan sesuai perannya oleh build aplikasi (`next build`) atau pemeriksaan tipe (`npm run typecheck`, yaitu `tsc --noEmit`), termasuk pemrosesan stylesheet CSS saat build:
   * `frontend/app/**` (Next.js App Router: seluruh pages, layouts, error boundaries, API route handlers)
   * `frontend/components/**` (Komponen UI yang diimpor oleh modul aplikasi)
   * `frontend/lib/**` (Modul utilitas, database client, skema, dan helper yang diimpor aplikasi)
   * `frontend/public/**` (Aset statis publik)
   * `frontend/src/**` (Source aplikasi tambahan bila ada)
   * `frontend/middleware.ts` (Next.js routing middleware yang dikompilasi)
   * `frontend/next.config.ts` (Konfigurasi build dan routing Next.js)
   * `frontend/postcss.config.mjs` (Konfigurasi build CSS yang mengaktifkan plugin `@tailwindcss/postcss` untuk memproses stylesheet aplikasi; `frontend/app/layout.tsx` mengimpor `globals.css` yang mengimpor Tailwind)
   * `frontend/tsconfig.json` (Konfigurasi TypeScript compiler)
   * `frontend/next-env.d.ts` (Deklarasi tipe TypeScript yang dibutuhkan oleh Next.js)

2. **Berkas Pendukung, Tooling, Generator & Dokumentasi (Supporting Files — Ikut Disalin oleh Copier)**:
   Berkas-berkas ini turut disalin oleh copier agar struktur direktori scratch tetap konsisten dengan repository, namun **bukan** merupakan prerequisite langsung dari eksekusi `next build` atau `typecheck`:
   * `frontend/tests/**` (Unit dan integrasi test scripts berbasis CommonJS, dijalankan oleh test runner terpisah, bukan compiler build)
   * `frontend/components.json` (Konfigurasi generator shadcn/ui untuk penambahan komponen baru di masa mendatang)
   * `frontend/eslint.config.mjs` (Konfigurasi linting ESLint, digunakan oleh perintah `npm run lint`)
   * `frontend/README.md`, `frontend/.gitignore` (Berkas dokumentasi dan aturan ignore yang ikut tersalin secara pasif)

3. **Pengecualian Eksplisit Copier (Exclusions)**:
   Copier secara sengaja mengecualikan dan **tidak menyalin**:
   * `frontend/node_modules/` (harus diinstal bersih di scratch, bukan disalin dari checkout)
   * `frontend/.next/` (cache kompilasi lokal Next.js)
   * `frontend/.git/` (metadata version control)
   * `frontend/tsconfig.tsbuildinfo` (cache build incremental TypeScript)
   * `frontend/.env*` (seluruh berkas environment/secret lokal)
   * Berkas symlink non-reguler (tidak diikuti)

### Kategori 4: Evidence Lokal dan Laporan Audit (TIDAK Diperlukan untuk Runner CI)
Berkas berikut merupakan artefak audit lokal dalam repository dan **tidak digunakan/dibutuhkan** oleh runner CI:
- `artifacts/**`
- Dokumen laporan `result_*.md` dan spesifikasi `WS-08_Hermes_*.md`

*Peringatan*: Pemisahan ini merupakan bahan evaluasi arsitektur dan kesiapan review; **bukan** otorisasi untuk melakukan mutasi Git (`git add`, `git commit`, `git push`, atau PR) secara otomatis.

---

## 4. Status Verifikasi dan Batasan Lingkungan

- **Verifikasi Sintetis Lokal**: Seluruh gerbang kontrol alur, penegakan prebuild ordering, penolakan negatif unpatched copy, immutabilitas target, persistensi snapshot raw, penolakan masukan salah, dan inkremental lockfile telah dibuktikan secara lokal melalui rangkaian pengujian sintetis terarah dan fixture independen Reviewer.
- **Pembuktian Serializer Runtime vs Helper Unit**: Penulisan raw initial dan final snapshots dibuktikan pada call sites fungsi `main()` melalui mocking interception dan eksekusi blok final berurutan, bukan sekadar nilai kembalian helper perbandingan.
- **Ubuntu historis**: Bundle run `39317803712`, commit `9ed4dceded97db9cdbd80734805886c3f8a9a73f`, ubuntu24/Node24.18.0/npm11.16.0 berstatus PASS pada salinan runner baseline lama. Itu bukan fresh activated-run proof. Updated remote CI setelah aktivasi **BELUM DIJALANKAN / NOT VERIFIED**.
- **Aktivasi & batas proof**: Canonical helper/patch/identity dan tiga hooks diaktifkan oleh task aktivasi; explicit verify di workflow utama berada sesudah npm ci sebelum checks. Local activation actual dan fixture delta checks dilaporkan dalam `result_WS-08_Hermes_Builder_Once_UI_Patch_Activation_CI_Integration.md`. Fixture/mock bukan instalasi nyata. Deployment ditunda, Header4/UI belum diintegrasikan, W8CF-R-F-01 tetap inherited/open. Independent review serta Git publication dilakukan terpisah.
