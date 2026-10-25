import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test } from 'vitest';
import { FileChips } from './file-chip';

afterEach(cleanup);

test('each file is a plain download link naming the file and its size', () => {
    render(
        <FileChips
            files={[
                { id: 1, url: '/file/abc', name: 'report.pdf', byteSize: 2048, sizeLabel: '2.0 KB', type: 'application/pdf' },
                { id: 2, url: '/file/def', name: 'minutes.docx', byteSize: 10, sizeLabel: '10 B', type: 'application/msword' },
            ]}
        />,
    );

    const first = screen.getByRole('link', { name: /report\.pdf/ });
    expect(first.getAttribute('href')).toBe('/file/abc');
    expect(first.getAttribute('target')).toBeNull();
    expect(first.textContent).toContain('2.0 KB');
    expect(screen.getAllByRole('link')).toHaveLength(2);
});

test('nothing is drawn for a post without files', () => {
    const { container } = render(<FileChips files={[]} />);

    expect(container.innerHTML).toBe('');
});
