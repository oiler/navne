<?php
// includes/Pipeline/Entity.php
namespace Navne\Pipeline;

class Entity {
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly float  $confidence
	) {}
}
