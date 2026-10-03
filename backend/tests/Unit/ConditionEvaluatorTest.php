<?php

namespace Tests\Unit;

use App\Services\ConditionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'equals is case-insensitive' => [['field' => 'country', 'operator' => 'equals', 'value' => 'india'], ['country' => 'India'], true],
            'not equals' => [['field' => 'country', 'operator' => 'not_equals', 'value' => 'India'], ['country' => 'Germany'], true],
            'contains' => [['field' => 'job_title', 'operator' => 'contains', 'value' => 'director'], ['job_title' => 'IT Director'], true],
            'greater than' => [['field' => 'budget', 'operator' => 'greater_than', 'value' => '10000'], ['budget' => '15000.00'], true],
            'greater than with null' => [['field' => 'budget', 'operator' => 'greater_than', 'value' => '10000'], ['budget' => null], false],
            'is empty' => [['field' => 'phone', 'operator' => 'is_empty'], ['phone' => null], true],
            'in list' => [['field' => 'priority', 'operator' => 'in', 'value' => 'high, urgent'], ['priority' => 'urgent'], true],
            'business email' => [['field' => 'email', 'operator' => 'business_email'], ['email' => 'jo@acme.io'], true],
            'free email is not business' => [['field' => 'email', 'operator' => 'business_email'], ['email' => 'jo@gmail.com'], false],
            'array contains' => [['field' => 'tags', 'operator' => 'contains', 'value' => 'vip'], ['tags' => ['Hot', 'VIP']], true],
            'unknown operator' => [['field' => 'x', 'operator' => 'nope', 'value' => '1'], ['x' => '1'], false],
        ];
    }

    #[DataProvider('cases')]
    public function test_condition_matching(array $condition, array $data, bool $expected): void
    {
        $this->assertSame($expected, (new ConditionEvaluator)->matches($condition, $data));
    }
}
