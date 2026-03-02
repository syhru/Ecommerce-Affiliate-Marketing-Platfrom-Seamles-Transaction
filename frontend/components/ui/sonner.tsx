"use client"

import {
  CircleCheckIcon,
  InfoIcon,
  Loader2Icon,
  OctagonXIcon,
  TriangleAlertIcon,
} from "lucide-react"
import { useTheme } from "next-themes"
import { Toaster as Sonner, type ToasterProps } from "sonner"

const Toaster = ({ ...props }: ToasterProps) => {
  const { theme = "system" } = useTheme()

  return (
    <Sonner
      theme={theme as ToasterProps["theme"]}
      className="toaster group"
      icons={{
        success: <CircleCheckIcon className="size-4" />,
        info: <InfoIcon className="size-4" />,
        warning: <TriangleAlertIcon className="size-4" />,
        error: <OctagonXIcon className="size-4" />,
        loading: <Loader2Icon className="size-4 animate-spin" />,
      }}
      toastOptions={{
        classNames: {
          toast:
            "group toast bg-white text-slate-900 border border-slate-200 shadow-xl rounded-2xl flex items-center p-4 gap-3 font-medium",
          description: "text-slate-500 text-sm",
          actionButton:
            "bg-slate-900 text-white hover:bg-slate-800 transition-colors rounded-lg font-bold px-3 py-1.5",
          cancelButton:
            "bg-slate-100 text-slate-500 hover:bg-slate-200 transition-colors rounded-lg font-bold px-3 py-1.5",
          success: "error-toast bg-emerald-50 text-emerald-900 border-emerald-200",
          error: "bg-red-50 text-red-900 border-red-200",
          warning: "bg-amber-50 text-amber-900 border-amber-200",
          info: "bg-blue-50 text-blue-900 border-blue-200",
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
