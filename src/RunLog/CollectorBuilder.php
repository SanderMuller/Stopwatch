<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Builds the optional run-log collectors ({@see ExceptionDetailRenderer},
 * {@see ContextCapture}) from a tolerant {@see ConfigReader} sub-section.
 *
 * @internal
 */
final class CollectorBuilder
{
    public static function exceptionRenderer(ConfigReader $options): ExceptionDetailRenderer
    {
        return new ExceptionDetailRenderer(new ExceptionDetail(
            messageEnabled: $options->bool('message'),
            messageMaxChars: $options->int('message_max_chars', 500),
            maskPatterns: $options->stringList('mask_message_matching'),
            traceFrames: $options->int('trace_frames', 10),
            traceExcludePaths: $options->stringList('trace_exclude_paths'),
        ));
    }

    public static function contextCapture(ConfigReader $options): ContextCapture
    {
        return new ContextCapture(
            allow: $options->stringList('allow'),
            deny: $options->stringList('deny'),
            mask: $options->stringList('mask'),
            frontmatterKeys: $options->stringList('frontmatter_keys'),
            valueMaxBytes: $options->int('value_max_bytes', 4096),
        );
    }
}
