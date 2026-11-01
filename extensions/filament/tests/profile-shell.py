#!/usr/bin/env python3
import json
import sys
import shell

if __name__ == '__main__':
    result, code = shell.run(configuration='profile-shell.xml', case_file='profile-cases.json')
    print(json.dumps(result))
    sys.exit(code)
