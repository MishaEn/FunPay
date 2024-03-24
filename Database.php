<?php

declare(strict_types=1);

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    /**
     * @var mysqli
     */
    private mysqli $mysqli;
    private bool $isTest;
    private const string CONDITION_BLOCK_START = '{';
    private const string CONDITION_BLOCK_END = '}';
    private const string INT_SPECIFIER = '?d';
    private const string FLOAT_SPECIFIER = '?f';
    private const string ARRAY_SPECIFIER = '?a';
    private const string ID_SPECIFIER = '?#';
    private const string PARAMETER_SPECIFIER = '?';
    private const array TYPE_LIST = ['string', 'integer', 'float', 'boolean'];
    private const array SPECIFIER_LIST = [
        self::INT_SPECIFIER,
        self::FLOAT_SPECIFIER,
        self::ARRAY_SPECIFIER,
        self::ID_SPECIFIER,
        self::PARAMETER_SPECIFIER
    ];
    private const string SKIP_VALUE = '~';
    private string $formatQuery = '';
    private array $args = [];
    private mixed $convertedArg;
    private string $specifier;

    private string $conditionalBlock;
    private string $blockFiller;

    /**
     * @param mysqli $mysqli
     */
    public function __construct(mysqli $mysqli, $isTest)
    {
        $this->mysqli = $mysqli;
        $this->isTest = $isTest;
    }

    /**
     * @param string $query
     * @param array $args
     * @return string
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        if ($args === []) {
            return $query;
        }

        return $this->init($query, $args)
            ->findAndReplaceSpecifier()
            ->findAndReplaceConditionalsBlock()
            ->escape();;
    }

    /**
     * Подоготовка перед форматированием запроса
     * @param string $query
     * @param array $args
     * @return self
     */
    private function init(string $query, array $args): self
    {
        $this->formatQuery = $query;
        $this->args = $args;

        return $this;
    }

    /**
     * Поиск и замена спецификаторов
     * @return self
     * @throws Exception
     */
    private function findAndReplaceSpecifier(): self
    {
        $specifierCount = substr_count($this->formatQuery, self::PARAMETER_SPECIFIER);
        for($i = 0; $i < $specifierCount; $i++) {
            $this->findSpecifiers()
                ->convertArg()
                ->replaceSpecifier();
        }

        return $this;
    }

    /**
     * Поиск и замена условных блоков
     * @return self
     * @throws Exception
     */
    private function findAndReplaceConditionalsBlock(): self
    {
        $this->findNestedConditionalsBlock()
            ->checkBlockIntegrity();

        $blocksStartCount = substr_count($this->formatQuery, self::CONDITION_BLOCK_START);

        for($i = 0; $i < $blocksStartCount; $i++) {
            $this->findConditionalsBlock()
                ->prepareConditionalBlock()
                ->replaceConditionalsBlock();
        }
        return $this;
    }

    /**
     * Поиск спецификаторов
     * @return self
     */
    private function findSpecifiers(): self
    {
        $escapedSpecifier = array_map('preg_quote', self::SPECIFIER_LIST);
        $pattern = '/(' . implode('|', $escapedSpecifier) . ')/';
        if (preg_match($pattern, $this->formatQuery, $matches, PREG_OFFSET_CAPTURE)) {
            $this->specifier = $matches[0][0];
        }

        return $this;
    }

    /**
     * Преобразование аргументов
     * @return self
     * @throws Exception
     */
    private function convertArg(): self
    {
        if ($this->args[0] === self::SKIP_VALUE) {
            $this->convertedArg = self::SKIP_VALUE;

            return $this;
        }

        $this->convertedArg = match ($this->specifier) {
            self::INT_SPECIFIER => $this->convertToInt($this->args[0]),
            self::FLOAT_SPECIFIER => $this->convertToFloat($this->args[0]),
            self::ARRAY_SPECIFIER => $this->convertToArray($this->args[0]),
            self::ID_SPECIFIER => $this->convertId($this->args[0]),
            self::PARAMETER_SPECIFIER => $this->convertParameter($this->args[0]),
        };

        return $this;
    }

    /**
     * Замена спецификаторов преобразованными аргументами
     * @return void
     */
    private function replaceSpecifier(): void
    {
        $pattern = '/(\\' . $this->specifier . ')/';
        $this->formatQuery = preg_replace($pattern, (string) $this->convertedArg, $this->formatQuery, 1);
        array_shift($this->args);
    }

    /**
     * Поиск условных блоков
     * @return self
     */
    private function findConditionalsBlock(): self
    {

        $pattern = '/\\'. self::CONDITION_BLOCK_START .'(?:[^'. self::CONDITION_BLOCK_START . self::CONDITION_BLOCK_END .']|(?R))*\\' . self::CONDITION_BLOCK_END . '/';

        if (preg_match($pattern, $this->formatQuery, $matches, PREG_OFFSET_CAPTURE)) {
            $this->conditionalBlock = $matches[0][0];
        }

        return $this;
    }

    /**
     * Подготовка условного блока
     * @return self
     */
    private function prepareConditionalBlock(): self
    {
        $isBlock = strpos($this->conditionalBlock, self::SKIP_VALUE);
        $pattern = '/[\{\}]/';
        $this->blockFiller = $isBlock ? '' : preg_replace($pattern, '', $this->conditionalBlock);

        return $this;
    }

    /**
     * Замена условного блока в итоговой строке
     * @return void
     */
    private function replaceConditionalsBlock(): void
    {
        $pattern = '/(\\' . $this->conditionalBlock . ')/';
        $this->formatQuery = preg_replace($pattern, $this->blockFiller, $this->formatQuery, 1);
    }

    /**
     * Проверка на целостность условного блока
     * @return void
     * @throws Exception
     */
    private function checkBlockIntegrity(): void
    {
        $blocksStartCount = substr_count($this->formatQuery, self::CONDITION_BLOCK_START);
        $blocksEndCount = substr_count($this->formatQuery, self::CONDITION_BLOCK_END);

        if ($blocksStartCount !== $blocksEndCount) {
            throw new Exception('Найден не закрытый условный блок');
        }
    }

    /**
     * Поиск вложенных условных блоков
     * @return self
     * @throws Exception
     */
    private function findNestedConditionalsBlock(): self
    {
        $pattern = '/\\'. self::CONDITION_BLOCK_START .'([^'. self::CONDITION_BLOCK_START . self::CONDITION_BLOCK_END .']*)\\'. self::CONDITION_BLOCK_END .'/u';

        if (preg_match_all($pattern, $this->formatQuery, $matches)) {
            foreach ($matches[0] as $match)
            {
                $patternCut = '/[\\' . self::CONDITION_BLOCK_START . '\\' . self::CONDITION_BLOCK_END . ']/';
                $match = preg_replace($patternCut, '', $match);
                if (preg_match_all($pattern, $match, $matches)) {
                    throw new Exception('Найден вложеный блок');
                }
            }
        }

        return $this;
    }

    /**
     * Преобразования аргумента для спецификатора "?" в соответсвии со списком типов self::TYPE_LIST
     * @param $arg
     * @return float|int|string
     * @throws Exception
     */
    private function convertParameter($arg): string|int|float
    {
        if (is_null($arg)) {
            return 'NULL';
        }

        $this->checkType($arg);

        if (is_string($arg) && !is_numeric($arg)) {
            return $this->wrapInSingleQuotes($arg);
        }

        if (is_bool($arg)) {
            return (int) $arg;
        }

        if (is_int($arg) || (is_string($arg) && ctype_digit($arg))) {
            return $this->convertToInt($arg);
        }

        if (is_float($arg) || !is_numeric($arg)) {
            return $this->convertToFloat($arg);
        }

        throw new Exception('Не удалось преобразовать тип');
    }

    /**
     * Преобразование аргумента в целое число
     * @param mixed $arg
     * @return string|int
     * @throws Exception
     */
    private function convertToInt(mixed $arg): string|int
    {
        if (is_null($arg)) {
            return  'NULL';
        }

        if (is_bool($arg)) {
            return (int) $arg;
        }

        if (!is_int($arg) && !(is_string($arg) && ctype_digit($arg))) {
            throw new Exception('Ожидается целочисленное');
        }

        return (int) $arg;
    }

    /**
     * Преобразование аргумента в число с плавающей точкой
     * @param mixed $arg
     * @return string|float
     * @throws Exception
     */
    private function convertToFloat(mixed $arg): string|float
    {
        if (is_null($arg)) {
            return  'NULL';
        }

        if (!is_float($arg) && !is_numeric($arg) || is_int($arg) || (is_string($arg) && ctype_digit($arg))) {
            throw new Exception('Ожидается десятичное');
        }

        return (float) $arg;
    }

    /**
     * Преобразование в список значений через запятую/в пары идентификатор и значение через запятую
     * @param mixed $args
     * @return string
     * @throws Exception
     */
    private function convertToArray(mixed $args): string
    {
        if (!is_array($args)) {
            throw new Exception('Ожидается массив');
        }

        if ($this->isAssoc($args) && !$this->isFullAssoc($args)) {
            throw new Exception('Ожидается ассоциатвиный массив');
        }

        if (!$this->isFullAssoc($args)) {
            $this->checkTypeMatchesInArray($args);
        }

        $rows = [];

        foreach ($args as $key => $arg) {
            $arg = $this->convertParameter($arg);

            if (!$this->isFullAssoc($args)) {
                $rows[] = $arg;
            }
            if ($this->isFullAssoc($args)) {
                $rows[] = $this->wrapInGravis($key) . ' = ' . $arg;
            }
        }

        return implode(', ', $rows);
    }

    /**
     * Оборот строки в грависы - "`"
     * @param string $arg
     * @return string
     */
    private function wrapInGravis(string $arg): string
    {
        return '`' . $arg . '`';
    }

    /**
     * Оборот строки в одиночные кавычки
     * @param string $arg
     * @return string
     */
    private function wrapInSingleQuotes(string $arg): string
    {
        return '\'' . $arg . '\'';
    }

    /**
     * Проверка, является ли массив полностью ассоциативным
     * @param array $arg
     * @return bool
     */
    private function isFullAssoc(array $arg): bool
    {
        return count(array_filter(array_keys($arg), 'is_string')) === count($arg);
    }

    /**
     * Проверка массива на признак ассоциативности
     * @param array $arg
     * @return bool
     */
    private function isAssoc(array $arg): bool
    {
        return is_array($arg) && array_diff_key($arg, array_keys(array_keys($arg)));
    }

    /**
     * Экранирование строки
     * @return void
     */
    private function escape(): string
    {
        return $this->isTest ? $this->formatQuery : $this->mysqli->real_escape_string();
    }

    /**
     * Проверка совпадения типов внутри списка
     * @param array $args
     * @return void
     * @throws Exception
     */
    private function checkTypeMatchesInArray(array $args): void
    {
        $firstType = gettype($args[0]);

        foreach ($args as $arg) {
            if (gettype($arg) !== $firstType && !is_null($arg)) {
                throw new Exception('Ожидаются одинаковые типы элементов в массиве');
            }
        }
    }

    /**
     * Проверка типа аргумента для спецификатора "?"
     * @param mixed $args
     * @return void
     * @throws Exception
     */
    private function checkType(mixed $args): void
    {
        $type = gettype($args);

        if (!in_array($type, self::TYPE_LIST)) {
            throw new Exception('Ожидается один из следующих типов: ' . implode(',', self::TYPE_LIST));
        }
    }

    /**
     * Преобразования идентификаторов в строку
     * @param mixed $arg
     * @return string
     */
    private function convertId(mixed $arg): string
    {
        $convertedString = '';
        $rows = [];

        if (is_string($arg)) {
            $convertedString = $this->wrapInGravis($arg);
        }

        if (is_array($arg)) {
            foreach ($arg as $item) {
                $rows[] = $this->wrapInGravis($item);
            }

            $convertedString = implode(', ', $rows);
        }

        return $convertedString;
    }

    /**
     * Вывод спецификатора для условного блока
     * @return string
     */
    public function skip(): string
    {
        return self::SKIP_VALUE;
    }
}
