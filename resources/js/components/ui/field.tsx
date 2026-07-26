import { cloneElement, type ComponentProps, isValidElement, type ReactElement, type ReactNode, useId } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Spend MemberFrame's `--frame-inset` below sm, then hand the padding back to the panel from sm up.
 * Shared by everything that sits in a `Panel bleed="full"` body — see the `inset` props here and on
 * {@link FormActions} / ImagesField, which must stay in step with that panel's padding.
 */
export const FRAME_INSET = 'px-(--frame-inset) sm:px-0';

type FieldProps = {
    label?: ReactNode;
    /** Ties the label to the control; defaults to a generated id when omitted. */
    htmlFor?: string;
    help?: ReactNode;
    /** Validation message (e.g. Inertia `form.errors.x`); rendered in place of help when set. */
    error?: string;
    required?: boolean;
    className?: string;
    /** Trailing content on the label row (e.g. a compact per-field visibility control). */
    labelRight?: ReactNode;
    /**
     * Pay the horizontal inset ourselves below sm. For a `Panel bleed="full"` body, which pays none so
     * that the compose editing surface inside it can reach both screen edges — every other child of
     * that body has to make up the difference or it sits at x=0.
     */
    inset?: boolean;
    /** The single control element. Field injects id / aria-invalid / aria-describedby onto it. */
    children: ReactNode;
};

/**
 * Label + help + error scaffolding around one control. Owns the a11y wiring: it gives help/error
 * deterministic ids and injects `id`, `aria-invalid`, and `aria-describedby` onto the control, so
 * every use site is programmatically associated without repeating the plumbing. Form state stays in
 * the caller's Inertia useForm; just pass `error`.
 */
export function Field({ label, htmlFor, help, error, required, className, labelRight, inset, children }: FieldProps) {
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
        <div className={cn('space-y-2', inset && FRAME_INSET, className)}>
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

/** A titled group of fields within a settings/form page. */
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
                <Heading className="text-base font-semibold text-foreground">{title}</Heading>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}

/** Trailing action row (submit/cancel). `inset` as on {@link Field}. */
export function FormActions({ inset, className, children }: { inset?: boolean; className?: string; children: ReactNode }) {
    return <div className={cn('flex flex-wrap items-center gap-3 pt-1', inset && FRAME_INSET, className)}>{children}</div>;
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
