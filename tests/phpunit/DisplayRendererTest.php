<?php

namespace MediaWiki\Extension\StructureSync\Tests;

use MediaWiki\Extension\StructureSync\Display\DisplayRenderer;
use MediaWiki\Extension\StructureSync\Display\DisplaySpecBuilder;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Schema\PropertyModel;
use PHPUnit\Framework\TestCase;
use PPFrame;

class DisplayRendererTest extends TestCase
{

    public function testRenderAllSections_RendersHtml()
    {
        // Mock SpecBuilder
        $specBuilder = $this->createMock(DisplaySpecBuilder::class);
        $specBuilder->method('buildSpec')->willReturn([
            'sections' => [
                [
                    'name' => 'Contact',
                    'properties' => ['Has email']
                ]
            ]
        ]);

        // Mock PropertyStore
        $prop = $this->createMock(PropertyModel::class);
        $prop->method('getDisplayLabel')->willReturn('Email Address');

        $store = $this->createMock(WikiPropertyStore::class);
        $store->method('readProperty')->with('Has email')->willReturn($prop);

        // Mock PPFrame
        $frame = $this->createMock(PPFrame::class);
        $frame->method('getArgument')->with('email')->willReturn('test@example.com');

        $renderer = new DisplayRenderer($store, $specBuilder);
        $html = $renderer->renderAllSections('Person', $frame);

        $this->assertStringContainsString('<div class="ss-section">', $html);
        $this->assertStringContainsString('<h2 class="ss-section-title">Contact</h2>', $html);
        $this->assertStringContainsString('<span class="ss-label">Email Address:</span>', $html);
        $this->assertStringContainsString('<span class="ss-value">test@example.com</span>', $html);
    }

    public function testRenderAllSections_SkipsEmptyValues()
    {
        // Mock SpecBuilder
        $specBuilder = $this->createMock(DisplaySpecBuilder::class);
        $specBuilder->method('buildSpec')->willReturn([
            'sections' => [
                [
                    'name' => 'Contact',
                    'properties' => ['Has email']
                ]
            ]
        ]);

        // Mock PropertyStore
        $store = $this->createMock(WikiPropertyStore::class);

        // Mock PPFrame - returns empty string
        $frame = $this->createMock(PPFrame::class);
        $frame->method('getArgument')->willReturn('');

        $renderer = new DisplayRenderer($store, $specBuilder);
        $html = $renderer->renderAllSections('Person', $frame);

        $this->assertEquals('', $html);
    }
}
