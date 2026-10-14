#!/usr/bin/env python3
"""Read-only SRBMiner log collector. Never starts, stops, or configures the miner."""
import hashlib,hmac,json,os,re,subprocess,time,uuid,urllib.request
URL=os.environ['HACHE_VAST_INGEST_URL']; KEY=os.environ['HACHE_VAST_INGEST_KEY'].encode(); INSTANCE=os.environ['HACHE_VAST_INSTANCE_ID']; LOG=os.environ.get('HACHE_SRBMINER_LOG','/var/log/prl-miner.log')
text=subprocess.check_output(['tail','-n','80',LOG],text=True,stderr=subprocess.DEVNULL)
# SRBMiner decorates individual cells with ANSI SGR sequences.  Remove those
# sequences first; otherwise a real #0/#1 statistics row is not parseable.
text=re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', text)
rows=re.findall(r'#(\d+)\s+(.+?)\s+(\d+(?:\.\d+)?)\s+TH/s\s+(\d+(?:\.\d+)?)W.*?\s+(\d+)C\s+\d+\s+\d+\s+(\d+)\s+(\d+)\s+(\d+)',text)
if not rows: raise SystemExit('no verified SRBMiner GPU rows')
gpu=[]; acc=rej=0
for i,model,h,w,t,a,r,hw in rows[-2:]:
    acc+=int(a);rej+=int(r);gpu.append({'index':int(i),'model':model.strip(),'hashrate_ths':float(h),'average_ths':None,'temp_c':int(t),'watts':float(w),'state':'mining' if float(h)>0 else 'inactive'})
payload={'instance_id':INSTANCE,'sample_id':str(uuid.uuid4()),'observed_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'total_ths':sum(x['hashrate_ths'] for x in gpu),'accepted_shares':acc,'rejected_shares':rej,'gpus':gpu}
raw=json.dumps(payload,separators=(',',':')).encode(); ts=str(int(time.time())); sig=hmac.new(KEY,ts.encode()+b'.'+raw,hashlib.sha256).hexdigest(); req=urllib.request.Request(URL,data=raw,method='POST',headers={'Content-Type':'application/json','X-Hache-Timestamp':ts,'X-Hache-Signature':sig}); urllib.request.urlopen(req,timeout=12).read()
