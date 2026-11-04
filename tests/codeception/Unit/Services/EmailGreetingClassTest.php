<?php

namespace Tests\Unit\Services;

use Codeception\Test\Unit;
use Tests\Support\Traits\ReadsEmailIntroBlocks;

/**
 * The email intro styles the greeting ("Hello %s,") as a heading. It is marked with a
 * class, so an admin intro that opens with a plain sentence is never styled as one.
 */
class EmailGreetingClassTest extends Unit
{
    use ReadsEmailIntroBlocks;

    public function test_greetings_carry_the_class_and_plain_sentences_do_not(): void
    {
        $blocks = $this->emailIntroBlocks();
        $this->assertGreaterThan(30, count($blocks), 'precondition: the email templates were found');

        $greetings = 0;
        foreach ($blocks as $template => $block) {
            $first = $this->firstIntroParagraph($block);
            if ($this->opensWithGreeting($first['body'])) {
                $greetings++;
                $this->assertStringContainsString('fct-email-greeting', $first['attributes'], $template . ' greeting is not marked');
            } else {
                $this->assertStringNotContainsString('fct-email-greeting', $first['attributes'], $template . ' styles a plain sentence as a greeting');
            }
            $this->assertLessThanOrEqual(1, substr_count($block, 'fct-email-greeting'), $template . ' marks more than the greeting');
        }
        $this->assertGreaterThan(20, $greetings, 'precondition: most customer intros open with a greeting');
    }

    public function test_admin_sentence_intros_stay_plain(): void
    {
        $blocks = $this->emailIntroBlocks();
        $plainIntros = [
            'order/reminder/due/admin.php',
            'order/reminder/overdue/admin.php',
            'order/refunded/admin.php',
            'subscription/canceled/admin.php',
            'subscription/reminder/admin.php',
            'subscription/trial_end/admin.php',
            'subscription/charge_failed/admin.php',
            'subscription/renewal_failed/admin.php',
        ];

        foreach ($plainIntros as $template) {
            $this->assertArrayHasKey($template, $blocks, $template . ' intro is found');
            $this->assertStringNotContainsString('fct-email-greeting', $blocks[$template], $template . ' opens with a sentence, not a greeting');
        }
    }

    public function test_shell_styles_the_class_not_the_first_paragraph(): void
    {
        $shell = file_get_contents($this->emailViewsDir() . '/general_template.php');

        $this->assertStringContainsString('.space_bottom_30 p.fct-email-greeting', $shell, 'The shell styles the greeting class');
        $this->assertStringNotContainsString(':first-child', $shell, 'The shell no longer guesses the greeting by position');
    }
}
