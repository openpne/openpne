import { Checkbox } from '@/components/ui/checkbox';
import { useT } from '@/lib/i18n';

/** The "Write in Markdown" checkbox on the compose forms. Drives the form's `format` field. */
export function MarkdownToggle({ checked, onChange }: { checked: boolean; onChange: (checked: boolean) => void }) {
    const t = useT();

    return (
        <label className="flex items-center gap-2 text-sm text-foreground">
            <Checkbox checked={checked} onChange={(e) => onChange(e.target.checked)} />
            {t('Write in Markdown')}
        </label>
    );
}
