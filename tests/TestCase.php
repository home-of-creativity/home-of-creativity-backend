<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\FakesOdooDocuments;

abstract class TestCase extends BaseTestCase
{
    use FakesOdooDocuments;
}
