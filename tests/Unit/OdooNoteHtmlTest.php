<?php

namespace Tests\Unit;

use App\Support\OdooNoteHtml;
use PHPUnit\Framework\TestCase;

class OdooNoteHtmlTest extends TestCase
{
    public function test_an_empty_table_and_empty_lines_at_the_end_are_cut(): void
    {
        $note = "<p><b>نتمنى لكم دوام النجاح</b></p><p>\u{00A0}</p><table class=\"table table-bordered o_table\">\n <tbody><tr>\n <td>\n <p><br></p>\n </td>\n </tr>\n </tbody></table><div><b>\n</b></div>";

        $this->assertSame('<p><b>نتمنى لكم دوام النجاح</b></p>', OdooNoteHtml::trimEmptyTail($note));
    }

    public function test_a_long_note_keeps_every_word_and_its_pictures(): void
    {
        $body = '';
        for ($i = 0; $i < 300; $i++) {
            $body .= "<p>◈ يتم سداد <b>50%</b> من قيمة العقد رقم {$i}\n    عند التعميد.</p>\n";
        }
        $body .= '<p><b><img class="img-fluid" src="/web/image/1139/image.png?access_token=x">طرق الدفع</b></p>';
        $note = $body."<p>\u{00A0}</p><table class=\"o_table\"><tbody><tr><td><p><br></p></td></tr></tbody></table>";

        $clean = OdooNoteHtml::trimEmptyTail($note);

        $this->assertStringContainsString('العقد رقم 299', $clean);
        $this->assertStringContainsString('/web/image/1139/image.png?access_token=x', $clean);
        $this->assertStringNotContainsString('o_table', $clean);
        $this->assertStringEndsWith('طرق الدفع</b></p>', $clean);
    }

    public function test_a_table_with_content_and_a_closing_picture_stay(): void
    {
        $table = '<p>الشروط</p><table class="o_table"><tbody><tr><td>50% مقدماً</td></tr></tbody></table>';
        $this->assertSame($table, OdooNoteHtml::trimEmptyTail($table));

        $picture = '<p>الحساب</p><p><img src="/web/image/1/qr.png"></p>';
        $this->assertSame($picture, OdooNoteHtml::trimEmptyTail($picture));
    }

    public function test_plain_text_is_kept(): void
    {
        $this->assertSame('الدفع خلال أسبوع', OdooNoteHtml::trimEmptyTail("الدفع خلال أسبوع\n\n"));
        $this->assertSame('', OdooNoteHtml::trimEmptyTail(''));
    }
}
