<?php

namespace App\Services;

use App\Models\EmailTemplate;

/**
 * TemplateService
 * Renders email templates stored in DB using {{variable}} substitution.
 */
class TemplateService
{
    private static array $templateCache = [];

    /**
     * Render a template by its key name.
     * Returns ['subject' => '...', 'body' => '...'] or null if not found.
     */
    public function render(string $templateKey, array $data): ?array
    {
        $template = $this->getTemplate($templateKey);
        if (!$template) {
            log_message('error', "TemplateService: template '{$templateKey}' not found or inactive.");
            return null;
        }

        $subject = $this->interpolate($template['subject'], $data);
        $body    = $this->interpolate($template['htmlContent'], $data);

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Get a raw template.
     */
    public function getTemplate(string $key): ?array
    {
        if (isset(self::$templateCache[$key])) {
            return self::$templateCache[$key];
        }

        $model    = new \App\Models\EmailTemplate();
        $template = $model->where('`key`', $key)->where('isActive', 1)->first();

        if (!$template) return null;

        self::$templateCache[$key] = $template;
        return $template;
    }

    /**
     * List all templates (for admin UI).
     */
    public function getAllTemplates(): array
    {
        $model = new \App\Models\EmailTemplate();
        return $model->orderBy('category', 'ASC')->orderBy('name', 'ASC')->findAll();
    }

    /**
     * Update a template.
     */
    public function updateTemplate(string $id, array $data): bool
    {
        $model = new \App\Models\EmailTemplate();

        $allowed = ['name', 'subject', 'htmlContent', 'textContent', 'isActive'];
        $update  = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        $update['updatedAt'] = date('Y-m-d H:i:s');

        // Invalidate cache
        $template = $model->find($id);
        if ($template) {
            unset(self::$templateCache[$template['key']]);
        }

        return $model->update($id, $update);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Simple {{key}} replacement + basic array loop support.
     *
     * Supports:
     *   {{name}}               → scalar
     *   {{#items}}...{{/items}} → loops array of associative arrays
     */
    private function interpolate(string $template, array $data): string
    {
        // 1. Handle array loops  {{#key}}...{{/key}}
        $template = preg_replace_callback(
            '/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s',
            function (array $m) use ($data) {
                $key   = $m[1];
                $inner = $m[2];
                $items = $data[$key] ?? [];
                if (!is_array($items)) return '';

                $result = '';
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        $result .= str_replace('{{.}}', htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8'), $inner);
                        continue;
                    }
                    $chunk = $inner;
                    foreach ($item as $k => $v) {
                        $chunk = str_replace('{{' . $k . '}}', htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'), $chunk);
                    }
                    $result .= $chunk;
                }
                return $result;
            },
            $template
        );

        // 2. Scalar replacements
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safe     = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
                $template = str_replace('{{' . $key . '}}', $safe, $template);
            }
        }

        return $template;
    }
}
