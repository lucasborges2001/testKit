#!/usr/bin/env python3
import argparse, os, pty, time
p=argparse.ArgumentParser(); p.add_argument('--ready',required=True); p.add_argument('--payload-hex',default=''); p.add_argument('--delay-ms',type=int,default=100); p.add_argument('--hold-ms',type=int,default=250); a=p.parse_args()
master,slave=pty.openpty(); path=os.ttyname(slave)
with open(a.ready,'w',encoding='utf-8') as f: f.write(path+'\n')
time.sleep(max(a.delay_ms,0)/1000)
if a.payload_hex: os.write(master, bytes.fromhex(a.payload_hex))
time.sleep(max(a.hold_ms,0)/1000)
