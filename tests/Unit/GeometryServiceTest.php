<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\GeometryService;

class GeometryServiceTest extends TestCase
{
    public function test_polygon_area_square()
    {
        $svc = new GeometryService();
        $points = [[0,0],[10,0],[10,10],[0,10]];
        $area = $svc->polygonArea($points);
        $this->assertEquals(100.0, $area);
    }

    public function test_polygon_area_triangle()
    {
        $svc = new GeometryService();
        $points = [[0,0],[4,0],[0,3]]; // area 6
        $area = $svc->polygonArea($points);
        $this->assertEquals(6.0, $area);
    }

    public function test_measurements_convert_square_metres_and_iou_is_reproducible()
    {
        $svc = new GeometryService();
        $geometry = ['type' => 'polygon', 'points' => [[0,0],[10,0],[10,10],[0,10]]];
        $measurement = $svc->measurements($geometry, 'FT', 1);

        $this->assertEquals(40.0, $measurement['perimeter']);
        $this->assertEquals(100.0, $measurement['area_sq_ft']);
        $this->assertEqualsWithDelta(9.290304, $measurement['area_sq_m'], 0.000001);
        $this->assertSame(1.0, $svc->estimateIoU($geometry['points'], $geometry['points']));
    }
}
