<?php

use Shakewellagency\LaravelPdfViewer\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createSamplePdfFile(string $filename = 'sample.pdf'): \Illuminate\Http\UploadedFile
{
    $pdfContent = "%PDF-1.4\n".
        "1 0 obj\n".
        "<<\n".
        "/Type /Catalog\n".
        "/Pages 2 0 R\n".
        ">>\n".
        "endobj\n".
        "2 0 obj\n".
        "<<\n".
        "/Type /Pages\n".
        "/Kids [3 0 R]\n".
        "/Count 1\n".
        ">>\n".
        "endobj\n".
        "3 0 obj\n".
        "<<\n".
        "/Type /Page\n".
        "/Parent 2 0 R\n".
        "/MediaBox [0 0 612 792]\n".
        "/Contents 4 0 R\n".
        ">>\n".
        "endobj\n".
        "4 0 obj\n".
        "<<\n".
        "/Length 44\n".
        ">>\n".
        "stream\n".
        "BT\n".
        "/F1 12 Tf\n".
        "100 700 Td\n".
        "(Test PDF Content) Tj\n".
        "ET\n".
        "endstream\n".
        "endobj\n".
        "xref\n".
        "0 5\n".
        "0000000000 65535 f \n".
        "0000000009 65535 n \n".
        "0000000074 65535 n \n".
        "0000000131 65535 n \n".
        "0000000214 65535 n \n".
        "trailer\n".
        "<<\n".
        "/Size 5\n".
        "/Root 1 0 R\n".
        ">>\n".
        "startxref\n".
        "309\n".
        "%%EOF\n";

    return \Illuminate\Http\UploadedFile::fake()->createWithContent(
        $filename,
        $pdfContent
    )->mimeType('application/pdf');
}

function generateMinimalPdfContent(): string
{
    return "%PDF-1.4\n".
        "1 0 obj\n".
        "<<\n".
        "/Type /Catalog\n".
        "/Pages 2 0 R\n".
        ">>\n".
        "endobj\n".
        "2 0 obj\n".
        "<<\n".
        "/Type /Pages\n".
        "/Kids [3 0 R]\n".
        "/Count 1\n".
        ">>\n".
        "endobj\n".
        "3 0 obj\n".
        "<<\n".
        "/Type /Page\n".
        "/Parent 2 0 R\n".
        "/MediaBox [0 0 612 792]\n".
        "/Contents 4 0 R\n".
        ">>\n".
        "endobj\n".
        "4 0 obj\n".
        "<<\n".
        "/Length 44\n".
        ">>\n".
        "stream\n".
        "BT\n".
        "/F1 12 Tf\n".
        "100 700 Td\n".
        "(Test PDF Content) Tj\n".
        "ET\n".
        "endstream\n".
        "endobj\n".
        "xref\n".
        "0 5\n".
        "0000000000 65535 f \n".
        "0000000009 65535 n \n".
        "0000000074 65535 n \n".
        "0000000131 65535 n \n".
        "0000000214 65535 n \n".
        "trailer\n".
        "<<\n".
        "/Size 5\n".
        "/Root 1 0 R\n".
        ">>\n".
        "startxref\n".
        "309\n".
        "%%EOF\n";
}

function actingAsTestUser(): \Illuminate\Foundation\Auth\User
{
    $user = new class extends \Illuminate\Foundation\Auth\User {
        protected $fillable = ['id', 'name', 'email'];

        public function __construct()
        {
            $this->id = fake()->uuid();
            $this->name = fake()->name();
            $this->email = fake()->email();
        }

        public function getAuthIdentifier(): string
        {
            return $this->id;
        }
    };

    test()->actingAs($user);

    return $user;
}
