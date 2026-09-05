import { Link } from '@inertiajs/react';
import type { VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// Link declares its own `size?: undefined`, which would collapse the intersection with the
// buttonVariants size — drop it in favor of the variant prop.
type Props = Omit<ComponentProps<typeof Link>, 'size'> & VariantProps<typeof buttonVariants>;

export function ActionLink({ className, variant, size, ...props }: Props) {
    return <Link className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
