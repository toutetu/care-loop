import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import { Loader2Icon } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[color,box-shadow] disabled:pointer-events-none disabled:opacity-60 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-xs hover:bg-primary/90",
        destructive:
          "bg-destructive text-white shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
        outline:
          "border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80",
        // 戻る・キャンセル・閉じる専用。押しても何も起きないことを見た目で伝える。
        // outline と secondary に散っていたキャンセルをここへ寄せる。
        neutral:
          "border border-input bg-background text-muted-foreground shadow-xs hover:bg-accent hover:text-foreground",
        ghost: "hover:bg-accent hover:text-accent-foreground",
        link: "text-primary underline-offset-4 hover:underline",
      },
      // 高さは44px以上を既定にしてある。職員は50〜60代が中心で、
      // 現場ではタブレットやスマートフォンを立ったまま操作する。
      // 44pxはiOS・Androidの双方が最小タッチ目標として挙げている値。
      size: {
        default: "h-11 px-5 py-2 has-[>svg]:px-4",
        // sm は「低い」ではなく「狭い」。高さは既定と同じ44pxを保ち、
        // 横幅だけ詰める。docs/mock/mobile.html の「タップ領域は最小
        // 44×44px」を、呼び出し側の判断に委ねず全サイズで守るため。
        sm: "h-11 rounded-md px-3.5 has-[>svg]:px-3",
        lg: "h-12 rounded-md px-7 has-[>svg]:px-5",
        icon: "size-11",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  pending = false,
  disabled,
  children,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    /**
     * 送信中かどうか。二重送信を止めるだけでなく、回転する印と
     * aria-busy を出す。opacity が下がるだけでは、押せたのか押せて
     * いないのかが分からないという声が現場から出やすい。
     */
    pending?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      disabled={asChild ? undefined : (disabled ?? pending)}
      aria-busy={pending || undefined}
      {...props}
    >
      {asChild ? (
        children
      ) : (
        <>
          {pending && <Loader2Icon className="animate-spin" aria-hidden />}
          {children}
        </>
      )}
    </Comp>
  )
}

export { Button, buttonVariants }
