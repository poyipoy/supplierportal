<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Single source of truth for the supplier registration Quality & Compliance questionnaire.
 *
 * Answers never block submission; an answer that differs from the expected one is only
 * flagged for the internal reviewer.
 */
final class SupplierComplianceQuestionnaire
{
    public const VERSION = 1;

    public const YES = 'yes';

    public const NO = 'no';

    /**
     * Question key => expected answer, in display order.
     *
     * @var array<string, string>
     */
    public const QUESTIONS = [
        'quality_standard' => self::YES,
        'quality_pic' => self::YES,
        'msds' => self::YES,
        'product_safe' => self::YES,
        'child_labor' => self::NO,
        'minimum_wage' => self::YES,
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::QUESTIONS);
    }

    /**
     * Validation rules for the `questionnaire[...]` input group.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = ['questionnaire' => ['required', 'array']];

        foreach (self::keys() as $key) {
            $rules['questionnaire.'.$key] = ['required', 'string', Rule::in([self::YES, self::NO])];
        }

        return $rules;
    }

    /**
     * Human-readable validation attribute names (the translated question text).
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = ['questionnaire' => __('registration.questionnaire.title')];

        foreach (self::keys() as $key) {
            $attributes['questionnaire.'.$key] = __('registration.questionnaire.questions.'.$key);
        }

        return $attributes;
    }

    /**
     * Keep only known keys with valid values, in canonical order.
     *
     * @return array<string, string>
     */
    public static function normalize(mixed $answers): array
    {
        $answers = is_array($answers) ? $answers : [];
        $normalized = [];

        foreach (self::keys() as $key) {
            $value = $answers[$key] ?? null;
            if (in_array($value, [self::YES, self::NO], true)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Stored payload for `suppliers.compliance_questionnaire`.
     *
     * @return array{version: int, answers: array<string, string>, answered_at: string}
     */
    public static function payload(mixed $answers): array
    {
        return [
            'version' => self::VERSION,
            'answers' => self::normalize($answers),
            'answered_at' => now()->toISOString(), // biz-time:ignore stored UTC instant
        ];
    }

    /**
     * Extract normalized answers from a stored payload (null-safe for legacy suppliers).
     *
     * @return array<string, string>
     */
    public static function answersFrom(mixed $stored): array
    {
        return self::normalize(is_array($stored) ? ($stored['answers'] ?? []) : []);
    }

    public static function isFlagged(string $key, ?string $answer): bool
    {
        return $answer !== null
            && array_key_exists($key, self::QUESTIONS)
            && self::QUESTIONS[$key] !== $answer;
    }

    /**
     * Question keys whose answer differs from the expected answer.
     *
     * @return list<string>
     */
    public static function flagged(array $answers): array
    {
        $flagged = [];

        foreach (self::normalize($answers) as $key => $answer) {
            if (self::isFlagged($key, $answer)) {
                $flagged[] = $key;
            }
        }

        return $flagged;
    }
}
