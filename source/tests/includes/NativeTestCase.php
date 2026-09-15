<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

/** Keep WordPress's deprecation assertions compatible with PHPUnit 11. */
#[\PHPUnit\Framework\Attributes\BackupGlobals(false)]
abstract class NativeTestCase extends \WP_UnitTestCase
{
    public function getAnnotations(): array
    {
        $annotations = ['class' => [], 'method' => []];
        $reflection = new \ReflectionClass($this);
        $comments = ['class' => $reflection->getDocComment(), 'method' => $reflection->getMethod($this->name())->getDocComment()];
        foreach ($comments as $depth => $comment) {
            preg_match_all('/@(expectedDeprecated|expectedIncorrectUsage)\s+(\S+)/', $comment ?: '', $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $annotations[$depth][$match[1]][] = $match[2];
            }
        }
        return $annotations;
    }
}
