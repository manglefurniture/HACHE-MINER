import re
line='\x1b[0m#0  RTX 5090  \x1b[32m379.67 TH/s\x1b[0m   497.4W  0.763  100%  68C  2272  13801  142  2  0'
clean=re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', line)
rows=re.findall(r'#(\d+)\s+(.+?)\s+(\d+(?:\.\d+)?)\s+TH/s\s+(\d+(?:\.\d+)?)W.*?\s+(\d+)C\s+\d+\s+\d+\s+(\d+)\s+(\d+)\s+(\d+)',clean)
assert rows and rows[0][0]=='0' and rows[0][2]=='379.67' and rows[0][5:]==('142','2','0')
print('vast collector regression ok')
