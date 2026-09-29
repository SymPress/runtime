# Security reports

Report vulnerabilities privately to brian.schaeffner@sympress.de. Include the affected runtime revision, a minimal reproduction and the expected trust boundary. Do not include production secrets or publish an exploit in a public issue before coordination.

This package is under development and has no production release yet. Project configuration, PHP providers, custom steps and explicit shell commands are trusted executable inputs. Static `validate` does not execute PHP providers or the run-only autoload file. Composer integration launches a separate PHP process; it does not provide a security sandbox for project code.

The parity matrix tracks remaining hardening and compatibility acceptance tests. A passing partial phase suite is not a claim that the complete replacement is ready for deployment.
