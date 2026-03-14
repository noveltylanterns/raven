<?php

declare(strict_types=1);

namespace Raven\Lib\Security;

/**
 * Shared recovery phrase generation and validation utilities.
 */
final class RecoveryPhrase
{
    /** @var array<int, string> */
    private const WORD_POOL = [
        'abandon', 'ability', 'able', 'about', 'above', 'absent', 'absorb', 'abstract',
        'absurd', 'abuse', 'access', 'accident', 'account', 'accuse', 'achieve', 'acid',
        'acoustic', 'acquire', 'across', 'act', 'action', 'actor', 'actual', 'adapt',
        'add', 'addict', 'address', 'adjust', 'admit', 'adult', 'advance', 'advice',
        'aerobic', 'affair', 'afford', 'afraid', 'again', 'age', 'agent', 'agree',
        'ahead', 'aim', 'air', 'airport', 'aisle', 'alarm', 'album', 'alert',
        'alien', 'all', 'alley', 'allow', 'almost', 'alone', 'alpha', 'already',
        'also', 'alter', 'always', 'amateur', 'amazing', 'among', 'amount', 'amused',
        'analyst', 'anchor', 'ancient', 'anger', 'angle', 'angry', 'animal', 'ankle',
        'announce', 'annual', 'another', 'answer', 'antenna', 'antique', 'anxiety', 'any',
        'apart', 'apology', 'appear', 'apple', 'approve', 'april', 'arch', 'arctic',
        'area', 'arena', 'argue', 'arm', 'armed', 'armor', 'army', 'around',
        'arrange', 'arrest', 'arrive', 'arrow', 'art', 'artist', 'ask', 'aspect',
        'assault', 'asset', 'assist', 'assume', 'asthma', 'athlete', 'atom', 'attack',
        'attend', 'attitude', 'attract', 'auction', 'audit', 'august', 'aunt', 'author',
        'auto', 'autumn', 'average', 'avocado', 'avoid', 'awake', 'aware', 'away',
        'awesome', 'awful', 'awkward', 'axis', 'baby', 'bachelor', 'bacon', 'badge',
        'bag', 'balance', 'balcony', 'ball', 'bamboo', 'banana', 'banner', 'bar',
        'barely', 'bargain', 'barrel', 'base', 'basic', 'basket', 'battle', 'beach',
        'bean', 'beauty', 'because', 'become', 'beef', 'before', 'begin', 'behave',
        'behind', 'believe', 'below', 'belt', 'bench', 'benefit', 'best', 'betray',
        'better', 'between', 'beyond', 'bicycle', 'bid', 'bike', 'bind', 'biology',
        'bird', 'birth', 'bitter', 'black', 'blade', 'blame', 'blanket', 'blast',
        'bleak', 'bless', 'blind', 'blood', 'blossom', 'blue', 'blur', 'board',
        'boat', 'body', 'boil', 'bomb', 'bone', 'bonus', 'book', 'boost',
        'border', 'boring', 'borrow', 'boss', 'bottom', 'bounce', 'box', 'boy',
        'bracket', 'brain', 'brand', 'brass', 'brave', 'bread', 'breeze', 'brick',
        'bridge', 'brief', 'bright', 'bring', 'brisk', 'broccoli', 'broken', 'bronze',
        'broom', 'brother', 'brown', 'brush', 'bubble', 'buddy', 'budget', 'buffalo',
        'build', 'bulb', 'bulk', 'bullet', 'bundle', 'bunker', 'burden', 'burger',
        'burst', 'business', 'busy', 'butter', 'buyer', 'buzz', 'cabin', 'cactus',
    ];

    public static function normalize(string $raw): string
    {
        $normalized = strtolower(trim($raw));
        $normalized = preg_replace('/[^a-z]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        return trim($normalized);
    }

    public static function isValid(string $phrase, int $wordCount = 12): bool
    {
        $wordCount = max(1, $wordCount);
        $phrase = self::normalize($phrase);
        if ($phrase === '') {
            return false;
        }

        $words = explode(' ', $phrase);
        if (count($words) !== $wordCount) {
            return false;
        }

        foreach ($words as $word) {
            if ($word === '' || preg_match('/^[a-z]{2,20}$/', $word) !== 1) {
                return false;
            }
        }

        return true;
    }

    public static function generate(int $wordCount = 12): ?string
    {
        $wordCount = max(1, $wordCount);
        $pool = self::WORD_POOL;
        $poolCount = count($pool);
        if ($poolCount < $wordCount) {
            return null;
        }

        $selectedIndices = [];
        try {
            while (count($selectedIndices) < $wordCount) {
                $index = random_int(0, $poolCount - 1);
                $selectedIndices[$index] = $index;
            }
        } catch (\Throwable) {
            return null;
        }

        $words = [];
        foreach (array_values($selectedIndices) as $index) {
            $word = trim((string) ($pool[$index] ?? ''));
            if ($word !== '') {
                $words[] = $word;
            }
        }

        $phrase = implode(' ', $words);
        if (!self::isValid($phrase, $wordCount)) {
            return null;
        }

        return $phrase;
    }
}
