<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\FormUpload;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class FormUploadTest extends TestCase
{
    public function test_returns_a_single_uploaded_file_passed_directly(): void
    {
        // Filament 5.6 single (non-multiple) FileUpload state: the UploadedFile itself.
        $file = UploadedFile::fake()->create('x.png');

        $this->assertSame($file, FormUpload::single($file));
    }

    public function test_returns_the_first_uploaded_file_from_a_keyed_array(): void
    {
        // Multiple / pre-5.6 shape: an array keyed by token.
        $file = UploadedFile::fake()->create('x.png');

        $this->assertSame($file, FormUpload::single(['abc-uuid' => $file]));
    }

    public function test_returns_null_for_empty_or_non_upload_state(): void
    {
        $this->assertNull(FormUpload::single(null));
        $this->assertNull(FormUpload::single([]));
        // A bare string must not be mistaken for an upload (the old (array)-cast bug).
        $this->assertNull(FormUpload::single('livewire-tmp/whatever.png'));
    }
}
