<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Message\UploadedFile;
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\UploadedFile as UploadedFileConstraint;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class UploadedFileTest extends TestCase
{
    use BuildsValidationRequests;

    private function request(array $body = [], array $files = []): ServerRequest
    {
        return ServerRequest::create('POST', '/')->withParsedBody($body)->withUploadedFiles($files);
    }

    private function file(int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile('path/to/file.txt', 10, $error, 'file.txt');
    }

    // ─── valid upload ──────────────────────────────────────────────────────

    public function test_valid_upload_passes(): void
    {
        $validator = new Validator(['avatar' => new UploadedFileConstraint()]);

        $result = $validator->validate($this->request([], ['avatar' => $this->file(UPLOAD_ERR_OK)]));

        $this->assertFalse($result->hasErrors());
    }

    // ─── failure codes ─────────────────────────────────────────────────────

    public function test_upload_error_codes_fail(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_EXTENSION] as $code) {
            $validator = new Validator(['avatar' => new UploadedFileConstraint()]);
            $result = $validator->validate($this->request([], ['avatar' => $this->file($code)]));
            $this->assertTrue($result->hasErrors(), "Expected fail for error code $code");
        }
    }

    // ─── no files ──────────────────────────────────────────────────────────

    public function test_no_files_fails(): void
    {
        $validator = new Validator(['avatar' => new UploadedFileConstraint()]);

        $result = $validator->validate($this->request());

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_file_field_fails(): void
    {
        $validator = new Validator(['avatar' => new UploadedFileConstraint()]);

        $result = $validator->validate($this->request(['avatar' => 'not-a-file']));

        $this->assertTrue($result->hasErrors());
    }

    // ─── nested in shape ───────────────────────────────────────────────────

    public function test_nested_in_shape(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'avatar' => new UploadedFileConstraint(),
            ]),
        ]);

        $result = $validator->validate(
            $this->request(['user' => []], ['avatar' => $this->file(UPLOAD_ERR_OK)]),
        );

        $this->assertFalse($result->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $validator = new Validator(['avatar' => new UploadedFileConstraint()]);

        $result = $validator->validate($this->request());

        $this->assertSame(
            ['The avatar must be a valid file.'],
            $result->errors()['avatar'],
        );
    }
}