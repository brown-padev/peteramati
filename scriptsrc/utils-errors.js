// utils-errors.js -- Peteramati JavaScript library
// Peteramati is Copyright (c) 2006-2020 Eddie Kohler
// See LICENSE for open-source distribution terms

import { hoturl } from "./hoturl.js";

export function log_jserror(errormsg, error, noconsole) {
    if (!error && errormsg instanceof Error) {
        error = errormsg;
        errormsg = {"error": error.toString()};
    } else if (typeof errormsg === "string") {
        errormsg = {"error": errormsg};
    }
    if (error && error.fileName && !errormsg.url) {
        errormsg.url = error.fileName;
    }
    if (error && error.lineNumber && !errormsg.lineno) {
        errormsg.lineno = error.lineNumber;
    }
    if (error && error.columnNumber && !errormsg.colno) {
        errormsg.colno = error.columnNumber;
    }
    if (error && error.stack) {
        errormsg.stack = error.stack;
    }
    $.ajax(hoturl("=api/jserror"), {
        global: false, method: "POST", cache: false, data: errormsg
    });
    if (error && !noconsole && typeof console === "object" && console.error) {
        console.error(errormsg.error);
    }
}


let old_onerror = window.onerror, nerrors_logged = 0;
window.onerror = function (errormsg, url, lineno, colno, error) {
    if ((url || !lineno)
        && errormsg.indexOf("ResizeObserver loop limit exceeded") < 0
        && ++nerrors_logged <= 10) {
        var x = {error: errormsg, url: url, lineno: lineno};
        if (colno) {
            x.colno = colno;
        }
        log_jserror(x, error, true);
    }
    return old_onerror ? old_onerror.apply(this, arguments) : false;
};
