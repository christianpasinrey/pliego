<?php

declare(strict_types=1);

use Pliego\Css\PageRuleData;
use Pliego\Css\Value\Length;
use Pliego\Page\CounterRef;
use Pliego\Page\MarginBoxContent;
use Pliego\Page\PageRuleFactory;

it('converts a null PageRuleData into a null PageRule', function () {
    expect(new PageRuleFactory()->fromCssData(null))->toBeNull();
});

it('converts declared margins to the matching per-side Length, leaving undeclared sides null', function () {
    $data = new PageRuleData(
        ['top' => Length::px(40.0), 'left' => Length::px(10.0)],
        [],
    );
    $pageRule = new PageRuleFactory()->fromCssData($data);
    expect($pageRule)->not->toBeNull();
    expect($pageRule?->marginTop)->toEqual(Length::px(40.0));
    expect($pageRule?->marginLeft)->toEqual(Length::px(10.0));
    expect($pageRule?->marginRight)->toBeNull();
    expect($pageRule?->marginBottom)->toBeNull();
});

it('converts all four declared margins', function () {
    $data = new PageRuleData(
        ['top' => Length::px(1.0), 'right' => Length::px(2.0), 'bottom' => Length::px(3.0), 'left' => Length::px(4.0)],
        [],
    );
    $pageRule = new PageRuleFactory()->fromCssData($data);
    expect($pageRule)->not->toBeNull();
    expect($pageRule?->marginTop)->toEqual(Length::px(1.0));
    expect($pageRule?->marginRight)->toEqual(Length::px(2.0));
    expect($pageRule?->marginBottom)->toEqual(Length::px(3.0));
    expect($pageRule?->marginLeft)->toEqual(Length::px(4.0));
});

it('converts margin box parts (string literals + counter sentinels) in order, keyed by position', function () {
    $data = new PageRuleData(
        [],
        [
            'top-center' => ['Pagina ', 'counter(page)', ' de ', 'counter(pages)'],
            'bottom-right' => ['x'],
        ],
    );
    $pageRule = new PageRuleFactory()->fromCssData($data);
    expect($pageRule)->not->toBeNull();
    expect($pageRule?->marginBoxes)->toHaveCount(2);
    expect($pageRule?->marginBoxes['top-center'])->toEqual(
        new MarginBoxContent(['Pagina ', CounterRef::Page, ' de ', CounterRef::Pages]),
    );
    expect($pageRule?->marginBoxes['bottom-right'])->toEqual(new MarginBoxContent(['x']));
});

it('drops an unsupported margin box position and reports a warning', function () {
    $data = new PageRuleData([], ['left-middle' => ['x'], 'top-center' => ['ok']]);
    $factory = new PageRuleFactory();
    $pageRule = $factory->fromCssData($data);
    expect($pageRule)->not->toBeNull();
    expect($pageRule?->marginBoxes)->toHaveCount(1);
    expect($pageRule?->marginBoxes)->toHaveKey('top-center');
    expect($factory->drainWarnings())->toEqual(['Unsupported margin box position: left-middle']);
});

it('drains warnings only once', function () {
    $factory = new PageRuleFactory();
    $factory->fromCssData(new PageRuleData([], ['bogus' => ['x']]));
    $factory->drainWarnings();
    expect($factory->drainWarnings())->toBe([]);
});
