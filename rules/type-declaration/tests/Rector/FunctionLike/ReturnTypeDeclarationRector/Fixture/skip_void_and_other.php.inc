<?php

namespace Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture;

class SkipVoidAndOther
{
    public function getSome($value)
    {
        if ($value !== 5) {
            return;
        }

        return $value !== 10;
    }
}
