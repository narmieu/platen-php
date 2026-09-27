<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/**
 * DocRaptor's error format: <errors><error>…</error></errors>.
 */
final class XmlErrorResponse extends Response
{
    /**
     * @param list<string> $messages
     */
    public function __construct(int $status, array $messages)
    {
        $items = '';
        foreach ($messages as $message) {
            $items .= '  <error>'.htmlspecialchars($message, \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')."</error>\n";
        }

        parent::__construct(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<errors>\n{$items}</errors>\n",
            $status,
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }
}
