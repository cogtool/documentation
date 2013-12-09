/*
 * @(#)launcher_md.c	1.3 01/02/14
 *
 * Copyright 1999-2001 by Sun Microsystems, Inc.,
 * 901 San Antonio Road, Palo Alto, California, 94303, U.S.A.
 * All rights reserved.
 *
 * This software is the confidential and proprietary information
 * of Sun Microsystems, Inc. ("Confidential Information").  You
 * shall not disclose such Confidential Information and shall use
 * it only in accordance with the terms of the license agreement
 * you entered into with Sun.
 */

#include "system.h"
#include "util.h"
#include "xmlparser.h"

int main(int argc, char **argv);

static Boolean gInitialized = false;
static Boolean gRunAppManager = false;

static char* GetWebStartAppName(void) {
  static char apppath[MAXPATHLEN];
  sprintf(apppath, "%s%cJava Web Start", sysGetApplicationHome(), FILE_SEPARATOR);
  return apppath;
}

static CFURLRef FindJnlpURLInFile(char* fileName) {
    XMLNode* doc = NULL;
	CFURLRef returnValue = NULL;
	char* jnlbuffer = NULL;
	
    /* Parse XML document. */
    if (!ReadFileToBuffer(fileName, &jnlbuffer)) {
        return NULL;
    }

    doc = ParseXMLDocument(jnlbuffer);
    if (doc != NULL) {
		XMLNode* node = NULL;
		char *codebase = NULL;
		char *href = NULL;
		CFStringRef baseURLString = NULL;
		CFStringRef hrefString = NULL;
		CFMutableStringRef fullURL = NULL;
		
		node = FindXMLChild(doc, "jnlp");
		require(node != NULL, bail);
		codebase = FindXMLAttribute(node->_attributes, "codebase");
		require(codebase != NULL, bail);
		href = FindXMLAttribute(node->_attributes, "href");
		require(href != NULL, bail);

		baseURLString = CFStringCreateWithCString(NULL, codebase, kCFStringEncodingUTF8);
		require(baseURLString != NULL, bail);

		fullURL = CFStringCreateMutableCopy(NULL, 0, baseURLString);
		hrefString = CFStringCreateWithCString(NULL, href, kCFStringEncodingUTF8);
		require(hrefString != NULL, bail);

		// a relative JNLP path needs a URL that starts at the specificed codebase
		if (!CFStringHasSuffix(fullURL, CFSTR("/")))
			CFStringAppend(fullURL, CFSTR("/"));
		CFStringAppend(fullURL, hrefString);
		
		returnValue = CFURLCreateWithString(NULL, fullURL, NULL);
bail:
		if (baseURLString != NULL) CFRelease(baseURLString);
		if (hrefString != NULL) CFRelease(hrefString);
		if (fullURL != NULL) CFRelease(fullURL);
		FreeXMLDocument(doc);
	}

	free(jnlbuffer);
	return returnValue;
}

static OSErr OpenDocEventHandler(const AppleEvent *theAppleEvent,
		       AppleEvent *reply,
		       long handlerRefcon) {

    char** argv  = NULL;
    int no;
    AEDesc fileListDesc = {'NULL', NULL};
    long numFiles;
    long actualSize;
    long index;
    OSErr err;
    DescType actualType;
    AEKeyword actualKeyword;
    FSSpec aFile;
    FSRef theFile;
    UInt8 fullPath[MAXPATHLEN];
	bool openWithWebStart = true;
	CFURLRef jnlpLocation = NULL;
  
    // Load up our list of file descriptors
    err = AEGetKeyDesc(theAppleEvent, keyDirectObject, typeAEList, &fileListDesc);
    
    if(err) {
        AEDisposeDesc(&fileListDesc);
        fprintf(stderr, "Error getting key desc\n");
        return err;
    }
    
    // How many files do we have to deal with?
    err = AECountItems(&fileListDesc, &numFiles);
    
    if(err) {
        AEDisposeDesc(&fileListDesc);
        fprintf(stderr, "Error counting items\n");
        return err;
    }
    
    // Iterate through all of the files, and try to send them through the JNLP java code.
    for(index = 1; index <= numFiles; index++) {
    
        err = AEGetNthPtr(&fileListDesc, index, typeFSS, &actualKeyword, 
                            &actualType, (Ptr)&aFile, sizeof(aFile), &actualSize);
        
        if(err) {
            AEDisposeDesc(&fileListDesc);
            fprintf(stderr, "Error getting file pointer\n");
            return err;
        }
        
        // Mac stuff to turn the file representation we get into a workable pathname
        FSpMakeFSRef(&aFile, &theFile);
        FSRefMakePath(&theFile, fullPath, sizeof(fullPath));   

		// See if we have an application for this JNLP file.  If so, use that instead.
		jnlpLocation = FindJnlpURLInFile(fullPath);

		if (jnlpLocation != NULL) {
			CFURLRef applicationURL = FindJNLPApplicationPackage(jnlpLocation);

			if (applicationURL != NULL) {
				OSStatus result = LSOpenCFURLRef(applicationURL, NULL);

				if (result == noErr) {
					openWithWebStart = false;
				}
			}

			CFRelease(jnlpLocation);
		}

		if (openWithWebStart) {
			// Three arguments -- app name, file to open, and null.
			argv = (char**)malloc(sizeof(char*) * 3);
			no = 0;
			argv[no++] = GetWebStartAppName();
			argv[no++] = fullPath;
			argv[no] = NULL;

			// Call into our main app.
			main(no, argv);			
		}

		openWithWebStart = TRUE;
    }
    
    QuitApplicationEventLoop();
    return noErr;
}


// If we get an open app event, we didn't get any files to open, so just
// leave gracefully and the java app will continue to run.
static OSErr OpenAppEventHandler(const AppleEvent *theAppleEvent,
		       AppleEvent *reply,
		       long handlerRefcon) {
    QuitApplicationEventLoop();
	gRunAppManager = true;
    return noErr;
}

/* 
 * If we got no arguments install the apple event handlers and our event loop.
 */
void LauncherSetup_md(int argc) {
    OSErr err;
    AEEventHandlerUPP openDocEventHandler;
    AEEventHandlerUPP openAppEventHandler;
	char **argv = NULL;
    int no = 0;
	
    // If we got more than one argument we were launched from the commandline,
    // so don't install any handlers.
    if (argc > 1)
        return;
        
    if (gInitialized)
        return;
        
    gInitialized = true;
	
    // We need to handle open events for the functionality we're looking for.
    openDocEventHandler = NewAEEventHandlerUPP((AEEventHandlerProcPtr)OpenDocEventHandler);
    openAppEventHandler = NewAEEventHandlerUPP((AEEventHandlerProcPtr)OpenAppEventHandler);
    
    err = AEInstallEventHandler(kCoreEventClass, kAEOpenDocuments, openDocEventHandler, 0, TRUE);
    if(err) {
        fprintf(stderr, "Error installing open event handler\n");
        exit(-1);
    }
     
    err = AEInstallEventHandler(kCoreEventClass, kAEOpenApplication, openAppEventHandler, 0, TRUE);
    if(err) {
        fprintf(stderr, "Error installing open app handler\n");
        exit(-1);
    }

    // Enter the event loop and handle appleevents.  If we were given files to open
    // they will appear here.
    RunApplicationEventLoop();

	if (gRunAppManager) {
		// Three arguments -- app name, no file, and null.
		argv = (char**)malloc(sizeof(char*) * 3);
		no = 0;
		argv[no++] = GetWebStartAppName();
		argv[no] = NULL;

		// Call into our main app.
		main(no, argv);
	} else {
		exit(0);		
	}
	
}


