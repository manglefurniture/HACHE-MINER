<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast.php';
function vast_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
vast_assert(miner_vast_valid_number('379.88',20000)===379.88,'valid measured TH/s accepted');
vast_assert(miner_vast_valid_number(-1,20000)===null,'negative rates rejected');
vast_assert(miner_vast_valid_number(20001,20000)===null,'implausible rates rejected');
vast_assert(miner_vast_valid_number('NaN',20000)===null,'non finite value rejected');
echo "vast regression ok\n";
