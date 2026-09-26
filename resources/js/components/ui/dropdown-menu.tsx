import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { DropdownMenu as DropdownMenuPrimitive } from 'radix-ui';
import { headingVariants } from '@/components/ui/heading';
import { cn } from '@/lib/utils';

export const DropdownMenu = DropdownMenuPrimitive.Root;
export const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;

export function DropdownMenuContent({
    className,
    sideOffset = 6,
    ...props
}: ComponentProps<typeof DropdownMenuPrimitive.Content>) {
    return (
        <DropdownMenuPrimitive.Portal>
            <DropdownMenuPrimitive.Content
                sideOffset={sideOffset}
                className={cn(
                    'z-50 min-w-56 overflow-hidden rounded-xl border border-border bg-card p-1 shadow-lg',
                    className,
                )}
                {...props}
            />
        </DropdownMenuPrimitive.Portal>
    );
}

export const dropdownMenuItemVariants = cva(
    'flex min-h-11 cursor-pointer select-none items-center gap-3 rounded-lg px-3 text-sm outline-none transition data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
    {
        variants: {
            variant: {
                default: 'text-foreground focus:bg-accent focus:text-accent-foreground',
                destructive: 'text-destructive focus:bg-destructive/10 focus:text-destructive',
            },
        },
        defaultVariants: { variant: 'default' },
    },
);

export function DropdownMenuItem({ className, variant, ...props }: ComponentProps<typeof DropdownMenuPrimitive.Item> & VariantProps<typeof dropdownMenuItemVariants>) {
    return <DropdownMenuPrimitive.Item className={cn(dropdownMenuItemVariants({ variant }), className)} {...props} />;
}

export function DropdownMenuSeparator({ className, ...props }: ComponentProps<typeof DropdownMenuPrimitive.Separator>) {
    return <DropdownMenuPrimitive.Separator className={cn('my-1 h-px bg-border', className)} {...props} />;
}

export const DropdownMenuRadioGroup = DropdownMenuPrimitive.RadioGroup;
export const DropdownMenuItemIndicator = DropdownMenuPrimitive.ItemIndicator;

export function DropdownMenuLabel({ className, ...props }: ComponentProps<typeof DropdownMenuPrimitive.Label>) {
    return <DropdownMenuPrimitive.Label className={cn(headingVariants({ variant: 'label' }), 'px-3 py-2', className)} {...props} />;
}

export function DropdownMenuRadioItem({ className, ...props }: ComponentProps<typeof DropdownMenuPrimitive.RadioItem>) {
    return (
        <DropdownMenuPrimitive.RadioItem
            className={cn(
                'flex min-h-11 cursor-pointer select-none items-start gap-3 rounded-lg px-3 py-2 text-sm text-foreground outline-none transition focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
                className,
            )}
            {...props}
        />
    );
}
