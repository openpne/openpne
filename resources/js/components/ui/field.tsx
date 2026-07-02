import { cloneElement, type ComponentProps, isValidElement, type ReactElement, type ReactNode, useId } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type FieldProps = {
    label?: ReactNode;
    /** Ties the label to the control; defaults to a generated id when omitted. */
    htmlFor?: string;
    help?: ReactNode;
    /** Validation message (e.g. Inertia `form.errors.x`); rendered in place of help when set. */
    error?: string;
    required?: boolean;
    className?: string;
    /** The single control element. Field injects id / aria-invalid / aria-describedby onto it. */
    children: ReactNode;
};

/**
 * Label + help + error scaffolding around one control. Owns the a11y wiring: it gives help/error
 * deterministic ids and injects `id`, `aria-invalid`, and `aria-describedby` onto the control, so
 * every use site is programmatically associated without repeating the plumbing. Form state stays in
 * the caller's Inertia useForm; just pass `error`.
 */
export function Field({ label, htmlFor, help, error, required, className, children }: FieldProps) {
    const generatedId = useId();
    // Canonical id: prefer the caller's htmlFor, else the child's own id, else a generated one — then
    // stamp that same id on both the label and the control so they can never desynchronize.
    const childId = isValidElement(children) ? ((children.props as Record<string, unknown>).id as string | undefined) : undefined;
    const id = htmlFor ?? childId ?? generatedId;
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = error ? errorId : helpId;

    const control = isValidElement(children)
        ? cloneElement(children as ReactElement<Record<string, unknown>>, {
              id,
              'aria-invalid': error ? true : (children.props as Record<string, unknown>)['aria-invalid'],
              'aria-describedby':
                  [(children.props as Record<string, unknown>)['aria-describedby'], describedBy].filter(Boolean).join(' ') || undefined,
          })
        : children;

    return (
        <div className={cn('space-y-2', className)}>
            {label && (
                <Label htmlFor={id}>
                    {label}
                    {required && <span className="text-destructive"> *</span>}
                </Label>
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

/** A titled group of fields within a settings/form page. */
export function FormSection({ title, description, children }: { title: ReactNode; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="space-y-4">
            <div className="space-y-0.5">
                <h2 className="text-base font-semibold text-foreground">{title}</h2>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}

/** Trailing action row (submit/cancel). */
export function FormActions({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('flex flex-wrap items-center gap-3 pt-1', className)}>{children}</div>;
}

/**
 * Group semantics for a set of RadioCards: a real fieldset with an (sr-only) legend for its
 * accessible name, and a group-level error tied via aria-describedby. `legend` mirrors the section
 * title so the visible heading is not duplicated for sighted users.
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

/** A single checkbox with its label and an associated error. */
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
