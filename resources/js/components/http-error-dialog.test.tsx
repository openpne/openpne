import { describe, expect, it } from 'vitest';
import { copyFor } from '@/components/http-error-dialog';

describe('copyFor', () => {
    it('maps the statuses a member can meet to their own words and action', () => {
        expect(copyFor(419)).toMatchObject({ title: 'Your session has expired', action: 'sign-in' });
        expect(copyFor(401).action).toBe('sign-in');
        expect(copyFor(403)).toMatchObject({ title: 'You cannot do that', action: 'close' });
        expect(copyFor(404)).toMatchObject({ title: 'Not Found', action: 'close' });
        expect(copyFor(429)).toMatchObject({ title: 'Too Many Requests', action: 'close' });
        expect(copyFor(503)).toMatchObject({ title: 'Under maintenance', action: 'reload' });
    });

    it('treats anything else as a generic failure with a reload on offer', () => {
        for (const status of [500, 502, 504, 418]) {
            expect(copyFor(status)).toMatchObject({ title: 'Something went wrong', action: 'reload' });
        }
    });
});
