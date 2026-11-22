<?php
namespace App\Modules\Workflows\Actions;
readonly class ActionResponse
{public function __construct(public string $status,public ?string $nextNodeId=null,public ?\DateTimeImmutable $resumeAt=null,public ?string $logMessage=null){}public static function proceed(?string $next=null,?string $log=null):self{return new self('PROCEED',$next,null,$log);}public static function pause(string $next,\DateTimeImmutable $resume,?string $log=null):self{return new self('PAUSE',$next,$resume,$log);}}
