<?php

declare(strict_types=1);

use ElgiborSolution\AdvancedReports\Support\PageNumberSettings;
use ElgiborSolution\AdvancedReports\Support\HeaderFooterStyle;

it('preserves legacy numbering and normalizes independent settings safely', function () {
    expect(PageNumberSettings::normalize([])['enabled'])->toBeFalse()
        ->and(PageNumberSettings::normalize(['showPageNumbers' => true, 'footerStyle' => ['alignment' => 'right']]))
        ->toMatchArray(['enabled' => true, 'position' => 'footer', 'alignment' => 'left', 'fontSize' => 9.0, 'edgeSpacing' => 18.0]);
    expect(PageNumberSettings::normalize(['footerStyle' => ['textColor' => '#ff0000']])['textColor'])->toBe('#000000')
        ->and(PageNumberSettings::normalize(['pageNumbers' => ['textColor' => '#336699']])['textColor'])->toBe('#336699')
        ->and(PageNumberSettings::normalize(['pageNumbers' => ['textColor' => 'red']])['textColor'])->toBe('#000000')
        ->and(PageNumberSettings::normalize(['pageNumbers' => ['textColor' => '#abc']])['textColor'])->toBe('#aabbcc');
    $numberStyle = PageNumberSettings::style(['footerStyle' => ['textColor' => '#ff0000', 'bold' => true]]);
    expect($numberStyle['textColor'])->toBe('#000000')
        ->and($numberStyle['bold'])->toBeTrue()
        ->and(HeaderFooterStyle::pdfRgb($numberStyle))->toBe([0, 0, 0])
        ->and(PageNumberSettings::pdfRgb(['footerStyle' => ['textColor' => '#ff0000']]))->toBe([0, 0, 0]);
    expect(PageNumberSettings::style(['footerStyle' => ['textColor' => '#ff0000'],
        'pageNumbers' => ['textColor' => '#0000ff']])['textColor'])->toBe('#0000ff')
        ->and(PageNumberSettings::pdfRgb(['footerStyle' => ['textColor' => '#ff0000'],
            'pageNumbers' => ['textColor' => '#0000ff']]))->toBe([0, 0, 1]);
    expect(PageNumberSettings::normalize(['pageNumbers' => ['position' => 'invalid', 'alignment' => 'invalid', 'fontSize' => 999, 'edgeSpacing' => -10]]))
        ->toMatchArray(['position' => 'footer', 'alignment' => 'right', 'fontSize' => 24.0, 'edgeSpacing' => 8.0]);
});

it('reserves multiline content and separate regions for every paper and orientation', function ($paper, $orientation, $position) {
    $layout = ['showPageNumbers' => true, 'headerText' => "Company\nDepartment", 'footerText' => "Confidential\nSecond line\nThird line",
        'pageNumbers' => ['position' => $position, 'contentSpacing' => 12, 'edgeSpacing' => 24]];
    $geometry = PageNumberSettings::geometry($layout, $paper, $orientation);
    $band = $geometry[$position];
    expect($band['offset'])->toBeGreaterThanOrEqual(24.0)
        ->and($band['inline'])->toBe($position === 'footer')
        ->and($band['margin'])->toBeGreaterThan($band['textHeight'] + $band['offset'])
        ->and($geometry['header']['margin'] + $geometry['footer']['margin'])->toBeLessThan($geometry['height'] - 72);
})->with(['a4', 'letter', 'legal'])->with(['portrait', 'landscape'])->with(['header', 'footer']);

it('wraps long and multiline content without discarding text or paragraph breaks', function () {
    $text = "longwordwithoutspaces\nTwo words here";
    $lines = PageNumberSettings::wrap($text, 30, fn ($value) => mb_strlen($value) * 6);
    expect(count($lines))->toBeGreaterThan(2);
    foreach ($lines as $line) expect(mb_strlen($line))->toBeLessThanOrEqual(5);
});

it('assigns disjoint footer widths and grows the row for wrapped content', function ($alignment) {
    $layout = ['showPageNumbers' => true, 'footerText' => str_repeat('Long footer text ', 3),
        'footerStyle' => ['fontSize' => 24, 'padding' => 8, 'borderStyle' => 'solid', 'borderWidth' => 2],
        'pageNumbers' => ['alignment' => $alignment, 'fontSize' => 24, 'contentSpacing' => 12]];
    $geometry = PageNumberSettings::geometry($layout, 'a4', 'portrait');
    $footer = $geometry['footer'];
    expect($footer['contentWidth'] + $footer['gap'] + $footer['numberWidth'])->toEqualWithDelta($geometry['width'] - 80, .001)
        ->and($footer['rowHeight'])->toBeGreaterThan($footer['numberHeight'])
        ->and($footer['numberFirst'])->toBe($alignment === 'left');
})->with(['left', 'center', 'right']);

it('reserves the full footer width for a counter without content and restores it when disabled', function () {
    $geometry = PageNumberSettings::geometry(['showPageNumbers' => true], 'a4', 'portrait');
    expect($geometry['footer']['numberWidth'])->toEqualWithDelta($geometry['width'] - 80, .001)
        ->and($geometry['footer']['contentWidth'])->toEqual(0)
        ->and($geometry['footer']['gap'])->toEqual(0);
    $disabled = PageNumberSettings::geometry(['showPageNumbers' => false, 'footerText' => 'Footer'], 'a4', 'portrait');
    expect($disabled['footer']['numberWidth'])->toEqual(0)
        ->and($disabled['footer']['contentWidth'])->toEqualWithDelta($geometry['width'] - 80, .001);
});
