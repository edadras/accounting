<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * What people call things, mapped onto the seeded category slugs.
 *
 * This is the knowledge the deterministic provider stands in for a model with.
 * It is not a toy: «شام» really is a restaurant expense and «اسنپ» really is a
 * taxi, and covering the everyday vocabulary means most drafts never need a
 * model at all — which is also the cheapest way to run this (docs/08 §7).
 *
 * A real model earns its keep on the long tail: an unfamiliar merchant name, a
 * sentence with two purchases in it, an ambiguous "پرداخت به علی".
 */
final class CategoryLexicon
{
    /**
     * Slug → the words that imply it. Longer phrases first within a slug: a
     * match on «قبض برق» should beat a match on «برق».
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'restaurant' => [
            'رستوران', 'فست فود', 'کافه', 'قهوه', 'شام', 'ناهار', 'نهار', 'صبحانه',
            'پیتزا', 'کباب', 'ساندویچ', 'برگر', 'دیزی',
            'restaurant', 'cafe', 'coffee', 'dinner', 'lunch', 'breakfast', 'pizza', 'burger', 'kebab',
        ],
        'groceries' => [
            'سوپرمارکت', 'هایپرمارکت', 'خواربار', 'بقالی', 'میوه', 'سبزی', 'خرید خانه',
            'groceries', 'grocery', 'supermarket', 'market', 'fruit',
        ],
        'bread' => ['نانوایی', 'نان', 'bakery', 'bread'],
        'meat' => ['قصابی', 'گوشت', 'مرغ', 'butcher', 'meat', 'chicken'],
        'rent' => ['اجاره', 'اجاره خانه', 'رهن', 'کرایه خانه', 'rent', 'landlord'],
        'electricity' => ['قبض برق', 'برق', 'electricity', 'power bill'],
        'water' => ['قبض آب', 'آب بها', 'water bill'],
        'gas' => ['قبض گاز', 'گاز شهری', 'gas bill'],
        'internet' => ['اینترنت', 'وایفای', 'internet', 'wifi', 'adsl', 'broadband'],
        'taxi' => ['تاکسی', 'اسنپ', 'تپسی', 'آژانس', 'taxi', 'uber', 'cab', 'snapp', 'ride'],
        'fuel' => ['پمپ بنزین', 'بنزین', 'گازوئیل', 'سوخت', 'fuel', 'petrol', 'gasoline', 'diesel'],
        'repairs' => ['تعمیرگاه', 'تعمیر', 'مکانیک', 'repair', 'mechanic', 'garage'],
        'doctor' => ['پزشک', 'دکتر', 'ویزیت', 'بیمارستان', 'کلینیک', 'doctor', 'clinic', 'hospital'],
        'pharmacy' => ['داروخانه', 'دارو', 'pharmacy', 'medicine', 'drugstore'],
        'insurance' => ['بیمه', 'insurance', 'premium'],
        'travel' => ['بلیط هواپیما', 'هتل', 'پرواز', 'بلیط', 'سفر', 'travel', 'hotel', 'flight', 'ticket'],
        'subscriptions' => ['اشتراک', 'نتفلیکس', 'اسپاتیفای', 'subscription', 'netflix', 'spotify'],
        'education' => ['شهریه', 'دانشگاه', 'کلاس', 'آموزشگاه', 'کتاب', 'tuition', 'course', 'school', 'university'],
        'salary' => ['حقوق', 'دستمزد', 'salary', 'payroll', 'wage'],
    ];

    /**
     * The best-matching slug for a description, with a confidence.
     *
     * Confidence rises with how much of the phrase matched: a hit on «قبض برق»
     * is worth more than one on «برق», which could be anything electrical.
     *
     * @return array{slug: string, keyword: string, confidence: float}|null
     */
    public static function match(string $normalized): ?array
    {
        $best = null;

        foreach (self::KEYWORDS as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                $needle = TextNormalizerBridge::normalize($keyword);
                $pattern = '/(?<![\p{L}])'.preg_quote($needle, '/').'(?![\p{L}])/u';

                if (preg_match($pattern, $normalized) !== 1) {
                    continue;
                }

                $length = mb_strlen($needle);

                if ($best === null || $length > mb_strlen($best['keyword'])) {
                    $best = ['slug' => $slug, 'keyword' => $needle];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        $words = substr_count(trim($best['keyword']), ' ') + 1;

        return [
            'slug' => $best['slug'],
            'keyword' => $best['keyword'],
            'confidence' => min(0.92, 0.72 + (0.1 * $words)),
        ];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::KEYWORDS);
    }

    /** True when the wording says money came in rather than went out. */
    public static function looksLikeIncome(string $normalized): bool
    {
        $markers = [
            'حقوق گرفتم', 'دریافت کردم', 'واریز شد', 'واریز کردند', 'گرفتم از',
            'درآمد', 'فروختم', 'پاداش', 'سود گرفتم',
            'received', 'was paid', 'got paid', 'salary', 'refund', 'income', 'deposited',
        ];

        foreach ($markers as $marker) {
            $needle = TextNormalizerBridge::normalize($marker);

            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
