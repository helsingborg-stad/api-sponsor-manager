<?php

namespace ApiSponsorManager;

use AcfService\Contracts\AddOptionsPage;
use AcfService\Contracts\GetField;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use WpService\Contracts\__;
use WpService\Contracts\AddAction;
use WpService\Contracts\GetPosts;
use WpService\Contracts\WpDeletePost;

class DeleteExpiredPost
{
    public function __construct(private GetPosts&WpDeletePost $wpService, private GetField $acfService)
    {}

    public function onCronEvent()
    {
        foreach ($this->getExpiredPosts() as $postId) {
            $this->wpService->wpDeletePost($postId);
        }
    }


    public function getExpiredPosts(?\DateTimeImmutable $now = null): array
    {
        $posts = $this->wpService->getPosts([
            'post_type' => ['assignment', 'offering'],
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $now ??= new \DateTimeImmutable();
        return array_filter($posts, fn($postId) => $this->postHasExpired($postId, $now));
    }

    private function postHasExpired(int $postId, \DateTimeImmutable $now): bool
    {
        $dueDate = $this->acfService->getField('due_date', $postId); // Y-m-d
        if (!is_string($dueDate) || $dueDate === '') {
            return false;
        }

        // Backa's calendar deadline includes the entire day in Helsingborg, not the receiver's UTC day.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, new \DateTimeZone('Europe/Stockholm'));
        if (!$date || $date->format('Y-m-d') !== $dueDate) {
            return false;
        }

        // Legacy due_time stays in storage but does not shorten the inclusive advertisement date.
        return $now >= $date->modify('+1 day');
    }
}
