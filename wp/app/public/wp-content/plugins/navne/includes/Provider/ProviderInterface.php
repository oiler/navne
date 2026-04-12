<?php
namespace Navne\Provider;

interface ProviderInterface {
	public function complete( string $prompt ): string;
}
