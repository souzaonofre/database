<?php

/**
 * Test: Nette\Database\Table\SqlBuilder: parseJoinConditions().
 * @dataProvider? ../databases.ini
 */

declare(strict_types=1);

use Nette\Database\Drivers\Engine;
use Nette\Database\Table\SqlBuilder;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$explorer = connectToDB();

Nette\Database\Helpers::loadFromFile($explorer, __DIR__ . "/../files/{$driverName}-nette_test1.sql");

class SqlBuilderMock extends SqlBuilder
{
	public function parseJoinConditions(&$joins, $joinConditions): array
	{
		return parent::parseJoinConditions($joins, $joinConditions);
	}


	public function buildJoinConditions(): array
	{
		return parent::buildJoinConditions();
	}


	public function parseJoins(&$joins, &$query): void
	{
		parent::parseJoins($joins, $query);
	}


	public function buildQueryJoins(array $joins, array $leftJoinConditions = []): string
	{
		return parent::buildQueryJoins($joins, $leftJoinConditions);
	}
}

$engine = $explorer->getDatabaseEngine();

test('detect circular join reference in nested conditions', function () use ($explorer) {
	$sqlBuilder = new SqlBuilderMock('author', $explorer);
	$sqlBuilder->addJoinCondition(':book(translator)', ':book(translator).translator_id = :book(translator).next_volume.translator_id');
	Assert::exception(
		fn() => $sqlBuilder->buildSelectQuery(),
		Nette\InvalidArgumentException::class,
		"Circular reference detected at left join conditions (tables ':book(translator)' => ':book(translator).next_volume' => ':book(translator)').",
	);

	$sqlBuilder = new SqlBuilderMock('author', $explorer);
	$sqlBuilder->addJoinCondition(':book.next_volume', ':book.next_volume.translator_id = :book.translator.id');
	$sqlBuilder->addJoinCondition(':book.translator', ':book.translator.id = :book.next_volume.translator_id');
	Assert::exception(
		fn() => $sqlBuilder->buildSelectQuery(),
		Nette\InvalidArgumentException::class,
		"Circular reference detected at left join conditions (tables ':book.next_volume' => ':book.translator' => ':book.next_volume').",
	);

	$sqlBuilder = new SqlBuilderMock('author', $explorer);
	$sqlBuilder->addJoinCondition(':book.next_volume', ':book.next_volume.translator_id = :book.translator.id');
	$sqlBuilder->addJoinCondition(':book.translator', ':book.translator.id = :book.auth.id');
	$sqlBuilder->addJoinCondition(':book.auth', ':book.auth.id = :book.next_volume.author_id');
	Assert::exception(
		fn() => $sqlBuilder->buildSelectQuery(),
		Nette\InvalidArgumentException::class,
		"Circular reference detected at left join conditions (tables ':book.next_volume' => ':book.translator' => ':book.auth' => ':book.next_volume').",
	);
});

test('combine join conditions with extra filters and parameters', function () use ($explorer, $engine) {
	$sqlBuilder = new SqlBuilderMock('author', $explorer);
	$sqlBuilder->addJoinCondition(':book(translator)', ':book(translator).id > ?', 2);
	$sqlBuilder->addJoinCondition(':book(translator):book_tag_alt', ':book(translator):book_tag_alt.state ?', 'private');
	$joins = [];
	$leftJoinConditions = $sqlBuilder->parseJoinConditions($joins, $sqlBuilder->buildJoinConditions());
	$join = $sqlBuilder->buildQueryJoins($joins, $leftJoinConditions);

	if ($engine->isSupported(Engine::SupportSchema)) {
		Assert::same(
			'LEFT JOIN book ON author.id = book.translator_id AND (book.id > ?) ' .
			'LEFT JOIN public.book_tag_alt book_tag_alt ON book.id = book_tag_alt.book_id AND (book_tag_alt.state = ?)',
			trim($join),
		);
	} else {
		Assert::same(
			'LEFT JOIN book ON author.id = book.translator_id AND (book.id > ?) ' .
			'LEFT JOIN book_tag_alt ON book.id = book_tag_alt.book_id AND (book_tag_alt.state = ?)',
			trim($join),
		);
	}

	Assert::same([2, 'private'], $sqlBuilder->getParameters());
});

test('apply date filter on related author in join condition', function () use ($explorer) {
	$sqlBuilder = new SqlBuilderMock('book', $explorer);
	$sqlBuilder->addJoinCondition('next_volume.author', 'next_volume.author.born >', '2000-01-01');

	Assert::same(['2000-01-01'], $sqlBuilder->getParameters());
});
