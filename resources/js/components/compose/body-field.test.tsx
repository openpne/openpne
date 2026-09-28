import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { BodyField } from './body-field';
import type { ComposeEditorPreference, ComposeFormat, RecordFormat } from './editor-mode';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@/components/markdown-preview', () => ({
    MarkdownPreview: ({ enabled }: { enabled: boolean }) => <div data-testid="preview" data-enabled={String(enabled)} />,
}));

const seams = vi.hoisted(() => ({
    answer: true,
    confirm: vi.fn(),
    save: vi.fn(),
    mounted: [] as string[],
}));
vi.mock('@/components/confirm-dialog', () => ({
    useConfirm: () => (options: unknown) => {
        seams.confirm(options);

        return Promise.resolve(seams.answer);
    },
}));
vi.mock('./save-compose-editor', () => ({ saveComposeEditor: seams.save }));
vi.mock('./rich-text-editor', async () => {
    const { useState } = await import('react');

    function RichStub({ initialMarkdown, ...rest }: { initialMarkdown: string; 'aria-describedby'?: string; 'aria-invalid'?: 'true' }) {
        // Once a mount, which is when the editor reads its text.
        useState(() => seams.mounted.push(initialMarkdown));

        return <div data-testid="rich" aria-describedby={rest['aria-describedby']} aria-invalid={rest['aria-invalid']} />;
    }

    return { default: RichStub };
});

const seen = { value: '', format: undefined as ComposeFormat | undefined, changes: 0 };
let write: (value: string) => void = () => {};

function Harness({
    body = 'a body',
    start,
    editorPreference = 'markdown',
    recordFormat,
    error,
}: {
    body?: string;
    start?: ComposeFormat;
    editorPreference?: ComposeEditorPreference;
    recordFormat?: RecordFormat;
    error?: string;
}) {
    const [value, setValue] = useState(body);
    const [format, setFormat] = useState(start);
    seen.value = value;
    seen.format = format;
    write = setValue;

    return (
        <BodyField
            id="body"
            label="Body"
            value={value}
            onChange={(next) => {
                seen.changes += 1;
                setValue(next);
            }}
            error={error}
            format={format}
            onFormatChange={setFormat}
            editorPreference={editorPreference}
            recordFormat={recordFormat}
        />
    );
}

async function choose(label: string) {
    fireEvent.keyDown(screen.getByTestId('compose-input-method-trigger'), { key: 'Enter' });
    await act(async () => {
        fireEvent.click(screen.getByRole('menuitemradio', { name: label }));
    });
}

const badge = () => screen.queryByTestId('compose-input-method-badge')?.textContent ?? null;

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    seams.answer = true;
    seams.mounted = [];
    seen.changes = 0;
});

test('an OpenPNE 3 record gets a plain field, its note and no way to change the method', () => {
    renderWithProviders(<Harness recordFormat="op3" />);

    expect((screen.getByRole('textbox', { name: 'Body' }) as HTMLTextAreaElement).value).toBe('a body');
    expect(screen.getByText('This entry keeps its OpenPNE 3 formatting.')).toBeTruthy();
    expect(screen.queryByTestId('compose-input-method-trigger')).toBeNull();
});

test('a stored entry asks before its format changes, and a refusal changes nothing', async () => {
    seams.answer = false;
    renderWithProviders(<Harness start="markdown" recordFormat="markdown" />);

    await choose('No formatting');

    expect(seams.confirm).toHaveBeenCalledTimes(1);
    expect(seams.confirm).toHaveBeenCalledWith(expect.objectContaining({ description: 'Formatting symbols in this entry will be shown as characters, exactly as typed.' }));
    expect(seen.format).toBe('markdown');
    expect(badge()).toBe('Markdown');
    expect(seams.save).not.toHaveBeenCalled();
});

test('an accepted change moves the format and the preference and leaves the text alone', async () => {
    renderWithProviders(<Harness start="markdown" recordFormat="markdown" />);

    await choose('No formatting');

    expect(seen.format).toBe('plain');
    expect(badge()).toBe('No formatting');
    expect(screen.getByTestId('preview').dataset.enabled).toBe('false');
    expect(seams.save).toHaveBeenCalledWith('plain');
    expect(seen.value).toBe('a body');
    expect(seen.changes).toBe(0);
});

test('a stored plain entry is told what Markdown will start to read', async () => {
    renderWithProviders(<Harness start="plain" recordFormat="plain" />);

    await choose('Use Markdown');

    expect(seams.confirm).toHaveBeenCalledWith(expect.objectContaining({ description: 'Symbols like # and * in this entry will start being treated as formatting.' }));
    expect(seen.format).toBe('markdown');
});

test('a new entry changes format unasked', async () => {
    renderWithProviders(<Harness start="markdown" />);

    await choose('No formatting');

    expect(seams.confirm).not.toHaveBeenCalled();
    expect(seen.format).toBe('plain');
});

test('a move that keeps the format is not asked about and still saved', async () => {
    renderWithProviders(<Harness start="markdown" recordFormat="markdown" />);

    await choose('Use formatting buttons');

    expect(seams.confirm).not.toHaveBeenCalled();
    expect(seen.format).toBe('markdown');
    expect(seams.save).toHaveBeenCalledWith('rich');
    expect(await screen.findByTestId('rich')).toBeTruthy();
});

test('choosing the method in use does nothing', async () => {
    renderWithProviders(<Harness start="markdown" />);

    await choose('Use Markdown');

    expect(seams.save).not.toHaveBeenCalled();
});

test('the rich editor is built again from the text as it stands each time it is entered', async () => {
    renderWithProviders(<Harness start="markdown" editorPreference="rich" />);
    await screen.findByTestId('rich');
    expect(seams.mounted).toEqual(['a body']);

    await choose('Use Markdown');
    act(() => write('rewritten'));
    await choose('Use formatting buttons');
    await screen.findByTestId('rich');

    expect(seams.mounted).toEqual(['a body', 'rewritten']);
});

test('a stored plain entry opens raw whatever the preference', () => {
    renderWithProviders(<Harness start="plain" editorPreference="rich" recordFormat="plain" />);

    expect(screen.queryByTestId('rich')).toBeNull();
    expect(badge()).toBe('No formatting');
});

test('the error is tied to the field in both editors', async () => {
    renderWithProviders(<Harness start="markdown" error="Too long." />);
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toBe('Too long.');
    const raw = screen.getByRole('textbox', { name: 'Body' });
    expect([raw.getAttribute('aria-invalid'), raw.getAttribute('aria-describedby')]).toEqual(['true', alert.id]);

    await choose('Use formatting buttons');
    const rich = await screen.findByTestId('rich');

    expect([rich.getAttribute('aria-invalid'), rich.getAttribute('aria-describedby')]).toEqual(['true', screen.getByRole('alert').id]);
});
