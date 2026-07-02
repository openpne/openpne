import { Link } from '@inertiajs/react';
import type { VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link> & VariantProps<typeof buttonVariants>;

/** An Inertia Link styled as a button (a navigation action, e.g. "Reply"), reusing buttonVariants. */
export function ActionLink({ className, variant, size, ...props }: Props) {
    return <Link className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
