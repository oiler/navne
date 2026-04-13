<?php
namespace Navne\Tests\Unit\BulkIndex;

use Navne\BulkIndex\Config;
use Navne\Tests\Unit\TestCase;

class ConfigTest extends TestCase {
	public function test_defaults_when_constants_not_defined(): void {
		$this->assertSame( 5,     Config::batch_size() );
		$this->assertSame( 60,    Config::batch_interval() );
		$this->assertSame( 0.002, Config::avg_cost_per_article() );
		$this->assertSame( 10,    Config::history_limit() );
		$this->assertSame( 500,   Config::max_error_len() );
	}
}
