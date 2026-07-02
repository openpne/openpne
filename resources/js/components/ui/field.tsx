import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type FieldProps = {
    label?: ReactNode;
    /** Ties the label to the control; also used by callers as the input id. */
    htmlFor?: string;
    help?: ReactNode;
    /** Validation message (e.g. Inertia `form.errors.x`); rendered in place of help when set. */
    error?: string;
    required?: boolean;
    className?: string;
    children: ReactNode;
};

/**
 * Label + help + error scaffolding around a control. Presentational only — the caller owns form
 * state (Inertia useForm) and passes `error`; wire `aria-invalid={!!error}` on the control for the
 * error ring, and `id={htmlFor}` to bind the label.
 */
export function Field({ label, htmlFor, help, error, required, className, children }: FieldProps) {
    return (
        <div className={cn('space-y-2', className)}>
            {label && (
                <Label htmlFor={htmlFor}>
                    {label}
                    {required && <span className="text-destructive"> *</span>}
                </Label>
            )}
            {children}
            {help && !error && <p className="text-xs text-muted-foreground">{help}</p>}
            {error && (
                <p role="alert" className="text-xs text-destructive">
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
