<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class LibraryTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    /*public function testValidateDiscovery(): void
    {
        $this->validateModule(__DIR__ . '/../GHDiscovery');
    }*/

    public function testValidateDiscovery(): void
    {
        $this->validateModule(__DIR__ . '/../GHDiscovery');
    }

    public function testValidatePlug(): void
    {
        $this->validateModule(__DIR__ . '/../GHPlug');
    }
}
