<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast-enrollment.php';
function expect_fail(array $value): void {
    try{
        miner_vast_enrollment_from_provider($value);
        throw new LogicException('unsafe enrollment accepted');
    }catch(DomainException $expected){}
}
$good=[
 'id'=>56667162,'machine_id'=>151300,'host_id'=>467312,
 'actual_status'=>'running','intended_status'=>'running',
 'num_gpus'=>8,'dph_total'=>4.290370,
 'ssh_host'=>'ssh8.vast.ai','ssh_port'=>10566,
];
$r=miner_vast_enrollment_from_provider($good);
if($r['instance_id']!=='56667162'||$r['machine_id']!=='151300'||
   $r['host_id']!=='467312'||$r['gpu_count']!==8||
   $r['hourly_usd']!=='4.290370')throw new LogicException('wrong projected inventory');
foreach([
 ['actual_status'=>'exited'], ['intended_status'=>'stopped'],
 ['id'=>'../../../etc/passwd'], ['machine_id'=>0],['host_id'=>null],
 ['num_gpus'=>0],['num_gpus'=>17],['num_gpus'=>'8'],
 ['dph_total'=>-1],['dph_total'=>'NaN'],['dph_total'=>101],
 ['ssh_host'=>null],['ssh_port'=>false],['ssh_port'=>0]
] as $changed)expect_fail(array_replace($good,$changed));

class EnrollmentDbFake extends PDO {
    public ?array $registered=null;
    private bool $tx=false;
    public array $sql=[];
    public function __construct(){}
    public function beginTransaction(): bool {$this->tx=true;return true;}
    public function inTransaction(): bool {return $this->tx;}
    public function commit(): bool {$this->tx=false;return true;}
    public function rollBack(): bool {$this->tx=false;return true;}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->sql[]=$query;
        if(!str_contains($query,'vast_instances'))throw new LogicException('Not restricted to Vast instances');
        return new EnrollmentStatementFake($this,$query);
    }
}
class EnrollmentStatementFake extends PDOStatement {
    private array $params=[];
    public function __construct(private EnrollmentDbFake $db,private string $query){}
    public function execute(?array $params=null): bool {
        $this->params=$params??[];
        if(str_starts_with($this->query,'INSERT INTO vast_instances')){
            if($this->db->registered!==null)throw new LogicException('Unexpected duplicate insert');
            $this->db->registered=[
                'instance_id'=>$this->params[0],'machine_id'=>$this->params[1],
                'host_id'=>$this->params[2],'gpu_count'=>$this->params[4],
                'hourly_usd'=>$this->params[5],'enabled'=>1,
            ];
        }
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,
                          int $cursorOffset=0): mixed {
        return $this->db->registered?:false;
    }
}
$fake=new EnrollmentDbFake();
$created=miner_vast_enrollment_write($fake,$r);
if($created['status']!=='enrolled'||$fake->registered===null||$fake->inTransaction())
    throw new LogicException('Enrollment was not inserted atomically');
$again=miner_vast_enrollment_write($fake,$r);
if($again['status']!=='already_enrolled'||count($fake->sql)!==3)
    throw new LogicException('Repeated request reinserted same ID');
$altered=$r;
$altered['machine_id']='999999';
try{
    miner_vast_enrollment_write($fake,$altered);
    throw new LogicException('Dangerous identity replacement accepted');
}catch(DomainException $expected){}
if($fake->inTransaction())throw new LogicException('Failed transaction left open');
echo "VAST_ENROLLMENT_REGRESSION_OK\n";
