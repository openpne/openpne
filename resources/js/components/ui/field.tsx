import { cloneElement, type ComponentProps, isValidElement, type ReactElement, type ReactNode, useId } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { headingVariants } from '@/components/ui/heading';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type FieldProps = {
    label?: ReactNode;
    htmlFor?: string;
    help?: ReactNode;
    error?: string;
    required?: boolean;
    className?: string;
    labelRight?: ReactNode;
    /** A single element, cloned to receive id / aria-invalid / aria-describedby. */
    children: ReactNode;
};

/**
 * Owns one control's a11y wiring, so no use site writes `aria-describedby` or the help/error ids
 * itself.
 */
export function Field({ label, htmlFor, help, error, required, className, labelRight, children }: FieldProps) {
    const generatedId = useId();
    const childId = isValidElement(children) ? ((children.props as Record<string, unknown>).id as string | undefined) : undefined;
    const id = htmlFor ?? childId ?? generatedId;
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = error ? errorId : helpId;

    const control = isValidElement(children)
        ? cloneElement(children as ReactElement<Record<string, unknown>>, {
              id,
              // These controls generally omit the native required attribute (validation is server-side),
              // so mirror the visual "*" to assistive tech via aria-required.
              'aria-required': required ? true : (children.props as Record<string, unknown>)['aria-required'],
              'aria-invalid': error ? true : (children.props as Record<string, unknown>)['aria-invalid'],
              'aria-describedby':
                  [(children.props as Record<string, unknown>)['aria-describedby'], describedBy].filter(Boolean).join(' ') || undefined,
          })
        : children;

    const labelNode = label && (
        <Label htmlFor={id}>
            {label}
            {required && <span className="text-destructive"> *</span>}
        </Label>
    );

    return (
        <div className={cn('space-y-2', className)}>
            {labelRight ? (
                <div className="flex items-center justify-between gap-2">
                    {labelNode ?? <span />}
                    {labelRight}
                </div>
            ) : (
                labelNode
            )}
            {control}
            {help && !error && (
                <p id={helpId} className="text-xs text-muted-foreground">
                    {help}
                </p>
            )}
            {error && (
                <p id={errorId} role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

export function FormSection({
    title,
    description,
    headingLevel: Heading = 'h2',
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    /** 'h3' when the section sits under a page-level h2 group heading (e.g. the settings page). */
    headingLevel?: 'h2' | 'h3';
    children: ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div className="space-y-0.5">
                <Heading className={headingVariants({ variant: 'section' })}>{title}</Heading>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}

export function FormActions({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('flex flex-wrap items-center gap-3 pt-1', className)}>{children}</div>;
}

/**
 * Pass the visible section title as `legend`: it is sr-only, so it names the group without
 * repeating on screen.
 */
export function RadioCardGroup({
    legend,
    error,
    className,
    children,
}: {
    legend: ReactNode;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    const id = useId();
    const errorId = error ? `${id}-error` : undefined;

    return (
        <fieldset className={cn('space-y-2', className)} aria-invalid={error ? true : undefined} aria-describedby={errorId}>
            <legend className="sr-only">{legend}</legend>
            {children}
            {error && (
                <p id={errorId} role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

export function CheckboxField({
    label,
    error,
    className,
    ...props
}: ComponentProps<typeof Checkbox> & { label: ReactNode; error?: string }) {
    const id = useId();
    const errorId = error ? `${id}-error` : undefined;

    return (
        <div className={cn('space-y-1', className)}>
            <label className="flex items-center gap-2 text-sm text-foreground">
                <Checkbox aria-invalid={error ? true : undefined} aria-describedby={errorId} {...props} />
                {label}
            </label>
            {error && (
                <p id={errorId} role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
