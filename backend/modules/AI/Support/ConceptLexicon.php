<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * The everyday concepts household money falls into, and the words people
 * actually write for them.
 *
 * This is what makes the local embedding provider semantic rather than
 * lexical. «بنزین» and «تعمیرگاه» share no letters with «ماشین», so no amount
 * of string matching or character n-gramming will ever bring them together;
 * they are close because of what they mean, and the only way to know that
 * without a trained model is to have been told. So here it is, written down.
 *
 * CategoryLexicon answers a different question — "which of this workspace's
 * categories is this?" — and its slugs are deliberately fine-grained: fuel and
 * repairs are separate categories precisely because a user budgets them
 * separately. Search needs the opposite: fuel, repairs, tyres, insurance and
 * parking are all "the car", which is what docs/08-ai-layer.md §4 asks for
 * when it says «تمام هزینه‌های مربوط به ماشین» must find all of them even
 * though they sit in different categories.
 *
 * A real embedding model knows this for every word in the language. This knows
 * it for the vocabulary a household budget is written in, which is most of
 * what gets typed into this product.
 */
final class ConceptLexicon
{
    /**
     * Concept → the words and phrases that imply it.
     *
     * A word may belong to several concepts: «بلیط» is travel, and «بیمه» is
     * insurance without being about a car until «خودرو» appears next to it.
     *
     * @var array<string, list<string>>
     */
    private const CONCEPTS = [
        'vehicle' => [
            'ماشین', 'خودرو', 'اتومبیل', 'خودروی', 'پراید', 'پژو',
            'بنزین', 'پمپ بنزین', 'جایگاه سوخت', 'گازوئیل', 'سوخت', 'باک',
            'تعمیرگاه', 'مکانیک', 'تعمیر ماشین', 'صافکاری', 'سرویس دوره ای',
            'لاستیک', 'تایر', 'باتری', 'روغن موتور', 'فیلتر روغن', 'کارواش',
            'پارکینگ', 'جریمه', 'خلافی', 'عوارض', 'معاینه فنی',
            'بیمه شخص ثالث', 'بیمه بدنه', 'بیمه خودرو', 'بیمه ماشین',
            'car', 'vehicle', 'automobile', 'fuel', 'petrol', 'gasoline', 'diesel',
            'garage', 'mechanic', 'tyre', 'tire', 'battery', 'engine oil', 'car wash',
            'parking', 'toll', 'traffic fine', 'car insurance',
        ],
        'food' => [
            'رستوران', 'کافه', 'قهوه', 'فست فود', 'پیتزا', 'برگر', 'ساندویچ', 'کباب',
            'شام', 'ناهار', 'نهار', 'صبحانه', 'غذا', 'خوراک', 'دیزی', 'بستنی',
            'سوپرمارکت', 'هایپرمارکت', 'خواربار', 'بقالی', 'نانوایی', 'نان',
            'قصابی', 'گوشت', 'مرغ', 'میوه', 'سبزی',
            'restaurant', 'cafe', 'coffee', 'dinner', 'lunch', 'breakfast', 'pizza',
            'burger', 'kebab', 'groceries', 'grocery', 'supermarket', 'bakery',
            'bread', 'butcher', 'meat', 'fruit', 'food',
        ],
        'home' => [
            'اجاره', 'رهن', 'کرایه خانه', 'شارژ ساختمان', 'خانه', 'آپارتمان',
            'قبض برق', 'برق', 'قبض آب', 'آب بها', 'قبض گاز', 'گاز شهری',
            'اینترنت', 'وایفای', 'تلفن', 'لوازم خانگی', 'مبلمان',
            'rent', 'landlord', 'mortgage', 'electricity', 'power bill', 'water bill',
            'gas bill', 'internet', 'wifi', 'broadband', 'furniture', 'utilities',
        ],
        'health' => [
            'پزشک', 'دکتر', 'ویزیت', 'بیمارستان', 'کلینیک', 'دندانپزشک', 'آزمایشگاه',
            'داروخانه', 'دارو', 'عینک', 'فیزیوتراپی', 'بیمه درمان', 'بیمه تکمیلی',
            'doctor', 'clinic', 'hospital', 'dentist', 'pharmacy', 'medicine',
            'drugstore', 'lab test', 'health insurance',
        ],
        'travel' => [
            'سفر', 'هتل', 'پرواز', 'بلیط', 'بلیط هواپیما', 'قطار', 'اتوبوس',
            'ویزا', 'تور', 'اقامتگاه', 'سوغات',
            'travel', 'trip', 'hotel', 'flight', 'ticket', 'train', 'bus', 'visa', 'tour',
        ],
        'transport' => [
            'تاکسی', 'اسنپ', 'تپسی', 'آژانس', 'مترو', 'اتوبوس', 'کرایه',
            'taxi', 'uber', 'cab', 'snapp', 'ride', 'metro', 'subway', 'fare',
        ],
        'education' => [
            'شهریه', 'دانشگاه', 'مدرسه', 'کلاس', 'آموزشگاه', 'کتاب', 'دوره',
            'tuition', 'course', 'school', 'university', 'book', 'class',
        ],
        'leisure' => [
            'سینما', 'کنسرت', 'تئاتر', 'باشگاه', 'ورزش', 'بازی', 'تفریح',
            'اشتراک', 'نتفلیکس', 'اسپاتیفای',
            'cinema', 'concert', 'theatre', 'gym', 'sport', 'game', 'subscription',
            'netflix', 'spotify',
        ],
        'clothing' => [
            'لباس', 'پوشاک', 'کفش', 'مانتو', 'پیراهن', 'خیاطی',
            'clothes', 'clothing', 'shoes', 'shirt', 'tailor',
        ],
        'income' => [
            'حقوق', 'دستمزد', 'درآمد', 'پاداش', 'واریز', 'اجاره دریافتی', 'سود سهام',
            'salary', 'payroll', 'wage', 'income', 'bonus', 'dividend', 'refund',
        ],
        'finance' => [
            'وام', 'قسط', 'چک', 'کارمزد', 'بهره', 'سرمایه گذاری', 'سهام', 'طلا', 'ارز',
            'loan', 'installment', 'cheque', 'check', 'fee', 'interest', 'investment',
            'stock', 'gold', 'currency',
        ],
    ];

    /**
     * Words that carry no topic and would only dilute a vector.
     *
     * Kept deliberately short: this is not a linguistic stop-word list, it is
     * the handful of connectives that show up in the way people phrase a
     * search — «تمام هزینه‌های مربوط به ماشین» is a query about cars, and
     * every word before «ماشین» is scaffolding.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'و', 'در', 'به', 'از', 'با', 'که', 'را', 'این', 'آن', 'برای', 'تا', 'یا',
        'های', 'ها', 'است', 'بود', 'شد', 'هم', 'همه', 'تمام', 'کل', 'مربوط', 'درباره',
        'چه', 'چی', 'کدام', 'من', 'ما', 'شما', 'خود', 'روی', 'بین', 'طی',
        'the', 'a', 'an', 'of', 'to', 'for', 'in', 'on', 'at', 'and', 'or', 'is',
        'are', 'was', 'were', 'be', 'all', 'any', 'my', 'me', 'our', 'your', 'about',
        'related', 'every', 'with', 'from', 'that', 'this', 'it', 'show',
    ];

    /**
     * Every concept the text touches, weighted by how many of its words hit.
     *
     * The text must already be normalised — it is matched against normalised
     * keywords, and folding it twice is both wasteful and, for the caller,
     * confusing about who owns that step.
     *
     * @return array<string, float>
     */
    public static function conceptsIn(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }

        $found = [];

        foreach (self::CONCEPTS as $concept => $keywords) {
            foreach ($keywords as $keyword) {
                $needle = TextNormalizerBridge::normalize($keyword);

                // Word boundaries, so «نان» does not fire inside «نانو» and
                // «car» does not fire inside «carpet».
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $normalized) === 1) {
                    $found[$concept] = ($found[$concept] ?? 0.0) + 1.0;
                }
            }
        }

        // A text that mentions six kinds of car part is not six times more
        // about cars than one that mentions petrol; the square root keeps a
        // long description from drowning out a short one.
        return array_map(static fn (float $hits): float => sqrt($hits), $found);
    }

    public static function isStopWord(string $token): bool
    {
        return in_array($token, self::STOP_WORDS, true);
    }
}
