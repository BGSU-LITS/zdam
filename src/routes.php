<?php

declare(strict_types=1);

use Lits\Action\ConvertAction;
use Lits\Action\ExcelAction;
use Lits\Action\IndexAction;
use Lits\Framework;

return function (Framework $framework): void {
    $framework->app()->get('/', IndexAction::class)
        ->setName('index');

    $framework->app()->get('/convert', ConvertAction::class)
        ->setName('convert')
        ->setArgument('auth', 'true');

    $framework->app()
        ->post('/convert', [ConvertAction::class, 'post'])
        ->setArgument('auth', 'true');

    $framework->app()->get('/excel', ExcelAction::class)
        ->setName('excel');
};
