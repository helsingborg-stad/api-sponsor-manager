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


    public function getExpiredPosts(): array
    {
        $posts = $this->wpService->getPosts([
            'post_type' => ['assignment', 'offering'],
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        return array_filter($posts, fn($postId) => $this->postHasExpired($postId));
    }

    private function postHasExpired(int $postId): bool
    {
        $dueDate = $this->acfService->getField('due_date', $postId); // d/m/Y
        $dueTime = $this->acfService->getField('due_time', $postId); // H:i:s

        if (empty($dueDate) || empty($dueTime)) {
            return false;
        }

        $expiredAt = \DateTime::createFromFormat('d/m/Y H:i:s', $dueDate . ' ' . $dueTime);

        if ($expiredAt === false) {
            return false;
        }

        return new \DateTime() >= $expiredAt;
    }
}
